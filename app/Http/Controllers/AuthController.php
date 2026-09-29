<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use App\Services\ActivityLogger;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        return DB::transaction(function () use ($request) {
            // Bloquea la cuenta para que dos accesos simultáneos no dejen más de una sesión activa.
            $user = User::where('email', $request->email)->lockForUpdate()->first();

            if (!$user || !Hash::check($request->password, $user->password)) {
                return response()->json([
                    'message' => 'Las credenciales proporcionadas son incorrectas.'
                ], 401);
            }

            if (isset($user->activo) && !$user->activo) {
                return response()->json([
                    'message' => 'El usuario se encuentra inactivo. Contacte al administrador.'
                ], 403);
            }

            // Una cuenta corresponde a una sola sesión operativa. Al ingresar en otro equipo,
            // los tokens anteriores dejan de ser válidos inmediatamente.
            $user->tokens()->delete();
            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'access_token' => $token,
                'token_type'   => 'Bearer',
                'user'         => [
                    'id'    => $user->id,
                    'name'  => $user->name,
                    'email' => $user->email,
                    'role'  => $user->role ?? 'cajero',
                ],
            ], 200);
        });
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada correctamente']);
    }

    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'password_actual' => 'required|string',
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        $user = $request->user();
        if (!Hash::check($data['password_actual'], $user->password)) {
            throw ValidationException::withMessages([
                'password_actual' => 'La contraseña actual no es correcta.',
            ]);
        }

        if (Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => 'La nueva contraseña debe ser diferente de la actual.',
            ]);
        }

        DB::transaction(function () use ($request, $user, $data) {
            $currentTokenId = $user->currentAccessToken()?->id;
            $user->update(['password' => $data['password']]);
            $otherTokens = $user->tokens();
            if ($currentTokenId) {
                $otherTokens->where('id', '!=', $currentTokenId);
            }
            $revokedSessions = $otherTokens->delete();

            ActivityLogger::log($request, 'user.password_changed', User::class, $user->id, [
                'sesiones_revocadas' => $revokedSessions,
            ]);
        });

        return response()->json(['message' => 'Contraseña actualizada correctamente.']);
    }
}
