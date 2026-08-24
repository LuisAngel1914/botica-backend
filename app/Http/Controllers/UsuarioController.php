<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UsuarioController extends Controller
{
    // Listar todos los usuarios
    public function index()
    {
        return response()->json(Usuario::all(), 200);
    }

    // Registrar un nuevo usuario (Cliente o Personal)
    public function store(Request $request)
    {
        $request->validate([
            'nombre_completo' => 'required|string|max:255',
            'email' => 'required|email|unique:usuarios,email',
            'password' => 'required|string|min:6',
        ]);

        $usuario = Usuario::create([
            'rol_id' => $request->rol_id ?? 2, // Por defecto rol 2
            'nombre_completo' => $request->nombre_completo,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'estado' => $request->estado ?? 'activo',
        ]);

        return response()->json(['message' => 'Usuario registrado con éxito', 'data' => $usuario], 201);
    }

    // Login simple de verificación
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $usuario = Usuario::where('email', $request->email)->first();

        if (!$usuario || !Hash::check($request->password, $usuario->password)) {
            return response()->json(['message' => 'Credenciales incorrectas'], 401);
        }

        return response()->json([
            'message' => 'Inicio de sesión exitoso',
            'user' => $usuario
        ], 200);
    }
}