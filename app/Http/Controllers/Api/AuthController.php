<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /** Reserve au staff : les clients s'authentifient via Firebase Auth (app Flutter). */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'       => ['required', 'email'],
            'password'    => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $user = User::where('email', $data['email'])->first();

        // Vérifier d'abord le rôle : les clients n'ont pas de mot de passe valide
        // (leur password DB est aléatoire, l'auth se fait via Firebase uniquement).
        if ($user && $user->isClient()) {
            throw ValidationException::withMessages([
                'email' => ['Ce compte est un compte client. Connectez-vous via l\'application mobile avec votre email Firebase.'],
            ]);
        }

        if (! $user || ! Hash::check($data['password'], $user->password) || ! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['Identifiants invalides ou compte désactivé.'],
            ]);
        }

        $token = $user->createToken($data['device_name'] ?? 'api')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user'  => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'role'  => $user->role->value,
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'id'    => $user->id,
            'name'  => $user->name,
            'email' => $user->email,
            'role'  => $user->role->value,
            'phone' => $user->phone,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Deconnecte.']);
    }
}
