<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    // Listar todos los usuarios
    public function index()
    {
        $usuarios = User::select('id', 'name', 'email', 'role', 'activo', 'created_at')
            ->orderBy('id', 'desc')
            ->get();

        return response()->json($usuarios, 200);
    }

    // Registrar un nuevo cajero/administrador
    public function store(Request $request)
    {
        $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:6',
            'role'     => 'required|in:admin,cajero'
        ]);

        $usuario = User::create([
            'name'     => $request->name,
            'email'    => $request->email,
            'password' => Hash::make($request->password),
            'role'     => $request->role,
            'activo'   => true
        ]);

        return response()->json([
            'message' => 'Usuario registrado exitosamente.',
            'usuario' => $usuario
        ], 201);
    }

    // Cambiar estado (Activar / Dar de baja)
    public function toggleEstado($id)
    {
        $usuario = User::findOrFail($id);
        $usuario->activo = !$usuario->activo;
        $usuario->save();

        $estadoStr = $usuario->activo ? 'activado' : 'dado de baja';

        return response()->json([
            'message' => "Usuario {$estadoStr} correctamente.",
            'usuario' => $usuario
        ], 200);
    }
}