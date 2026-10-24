<?php

namespace App\Http\Controllers\Api\Partner;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterPartnerRequest;
use App\Http\Resources\PartnerAccountResource;
use App\Models\PartnerAccount;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterPartnerRequest $request): JsonResponse
    {
        $data = $request->validated();

        return DB::transaction(function () use ($request, $data) {
            $user = User::create([
                'name' => $data['contact_name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'phone' => $data['phone'],
                'role' => UserRole::Partner,
                'is_active' => true,
            ]);
            $account = PartnerAccount::create([
                'user_id' => $user->id,
                'company_name' => $data['company_name'],
                'contact_name' => $data['contact_name'],
                'phone' => $data['phone'],
                'whatsapp' => $data['whatsapp'] ?? null,
                'status' => 'pending',
            ]);
            $user->setRelation('partnerAccount', $account);

            return response()->json(array_merge(
                $this->profile($request, $user),
                [
                    'message' => 'Inscription recue. Votre entreprise doit etre approuvee avant de publier des annonces.',
                    'token' => $user->createToken($data['device_name'] ?? 'partner', ['partner'])->plainTextToken,
                ],
            ), 201);
        });
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:72'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        return DB::transaction(function () use ($request, $data) {
            $user = User::query()
                ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($data['email']))])
                ->lockForUpdate()
                ->first();

            if (! $user || ! Hash::check($data['password'], $user->password) || ! $user->isPartner() || ! $user->is_active) {
                throw ValidationException::withMessages(['email' => ['Identifiants invalides ou compte desactive.']]);
            }

            $account = $user->partnerAccount()->lockForUpdate()->first();
            abort_unless($account, 403, 'Compte partenaire introuvable.');
            if (in_array($account->status, ['rejected', 'suspended'], true)) {
                return response()->json([
                    'message' => 'Votre compte partenaire ne peut pas se connecter dans son etat actuel.',
                    'status' => $account->status,
                    'review_reason' => $account->review_reason,
                ], 403);
            }
            $user->setRelation('partnerAccount', $account);

            return response()->json(array_merge(
                $this->profile($request, $user),
                ['token' => $user->createToken($data['device_name'] ?? 'partner', ['partner'])->plainTextToken],
            ));
        });
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->profile($request, $request->user()));
    }

    private function profile(Request $request, User $user): array
    {
        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'role' => $user->role->value,
            ],
            'partner_account' => (new PartnerAccountResource($user->partnerAccount))->resolve($request),
        ];
    }
}
