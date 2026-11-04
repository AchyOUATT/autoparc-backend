<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\AccountPasswordReset;
use App\Services\AutoParcContact;
use App\Services\PasswordRecovery;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Throwable;

class PasswordRecoveryController extends Controller
{
    public function forgot(Request $request, PasswordRecovery $recovery): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'account_type' => ['required', 'string', Rule::in(['partner', 'staff'])],
            'locale' => ['nullable', 'string', Rule::in(['fr', 'en'])],
        ]);
        $locale = $data['locale'] ?? (app()->getLocale() === 'en' ? 'en' : 'fr');

        // This check must precede the account lookup: availability cannot
        // reveal whether the requested account exists or which role it has.
        if (! $recovery->deliveryAvailable()) {
            return response()->json([
                'code' => 'password_reset_unavailable',
                'message' => Lang::get('password_recovery.unavailable', [], $locale),
            ], 503)->header('Cache-Control', 'no-store, private');
        }

        try {
            Password::broker('users')->sendResetLink(
                $recovery->credentials($data['email'], $data['account_type']),
                function (User $user, string $token) use ($data, $locale): void {
                    try {
                        $user->notify(new AccountPasswordReset($token, $data['account_type'], $locale));
                    } catch (Throwable $error) {
                        Password::broker('users')->deleteToken($user);

                        throw $error;
                    }
                },
            );
        } catch (Throwable) {
            // Do not log addresses, reset URLs, credentials or transport errors
            // containing the mail body. Keep the response indistinguishable
            // from an unknown account; support remains available in the app.
            Log::error('password_reset_delivery_failed');
        }

        return response()->json([
            'code' => 'password_reset_requested',
            'message' => Lang::get('password_recovery.requested', [], $locale),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function show(Request $request, string $token, PasswordRecovery $recovery): Response
    {
        return $this->page($request, $this->validRequest($request, $token, $recovery) ? 'form' : 'invalid');
    }

    public function reset(Request $request, string $token, PasswordRecovery $recovery): Response
    {
        if (! $this->validRequest($request, $token, $recovery)) {
            return $this->page($request, 'invalid', status: 422);
        }

        $previousLocale = app()->getLocale();
        app()->setLocale($this->locale($request));
        try {
            $validator = Validator::make($request->only(['password', 'password_confirmation']), [
                'password' => ['bail', 'required', 'string', 'confirmed', 'max:72', function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value) && strlen($value) > 72) {
                        $fail(__('password_recovery.password_too_long'));
                    } elseif (is_string($value) && str_contains($value, "\0")) {
                        $fail(__('password_recovery.password_invalid'));
                    }
                }, PasswordRule::min(12)->letters()->numbers()],
            ], [
                'password.required' => __('password_recovery.password_invalid'),
                'password.string' => __('password_recovery.password_invalid'),
                'password.confirmed' => __('password_recovery.confirmation_invalid'),
                'password.max' => __('password_recovery.password_too_long'),
            ]);
            if ($validator->fails()) {
                return $this->page($request, 'form', $validator->errors()->all(), 422);
            }
        } finally {
            app()->setLocale($previousLocale);
        }

        $credentials = $recovery->credentials((string) $request->query('email'), (string) $request->query('account_type'));
        $credentials['token'] = $token;
        $credentials['password'] = $validator->validated()['password'];

        // Serialise consumption of this token. Both a second request and a
        // replay see the deleted token, not a previously validated copy.
        $result = DB::transaction(function () use ($credentials) {
            $broker = Password::broker('users');
            $user = $broker->getUser($credentials);
            if (! $user) {
                return Password::INVALID_USER;
            }
            User::query()->whereKey($user->getKey())->lockForUpdate()->first();
            DB::table((string) config('auth.passwords.users.table'))->where('email', $user->getEmailForPasswordReset())->lockForUpdate()->first();

            return $broker->reset($credentials, function (User $user, string $password): void {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                $user->tokens()->delete();
                event(new PasswordReset($user));
            });
        });

        return $this->page($request, $result === Password::PASSWORD_RESET ? 'success' : 'invalid', status: $result === Password::PASSWORD_RESET ? 200 : 422);
    }

    private function validRequest(Request $request, string $token, PasswordRecovery $recovery): bool
    {
        if (! preg_match('/\A[a-f0-9]{64}\z/', $token) || ! $request->hasValidSignature(absolute: false)) {
            return false;
        }
        $data = $request->query();
        if (Validator::make($data, [
            'email' => ['required', 'string', 'email', 'max:255'],
            'account_type' => ['required', 'string', Rule::in(['partner', 'staff'])],
            'locale' => ['required', 'string', Rule::in(['fr', 'en'])],
        ])->fails()) {
            return false;
        }
        $broker = Password::broker('users');
        $user = $broker->getUser($recovery->credentials($data['email'], $data['account_type']));

        return $user !== null && $broker->tokenExists($user, $token);
    }

    private function locale(Request $request): string
    {
        return $request->query('locale') === 'en' ? 'en' : 'fr';
    }

    private function page(Request $request, string $state, array $errors = [], int $status = 200): Response
    {
        $locale = $this->locale($request);

        return response()->view('auth.reset-password', [
            'texts' => Lang::get('password_recovery', [], $locale),
            'locale' => $locale,
            'state' => $state,
            'validationErrors' => $errors,
            'action' => $request->getRequestUri(),
            'phone' => app(AutoParcContact::class)->phone(),
        ], $status)->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, nofollow',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'",
        ]);
    }
}
