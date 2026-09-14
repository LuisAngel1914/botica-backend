<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

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
}