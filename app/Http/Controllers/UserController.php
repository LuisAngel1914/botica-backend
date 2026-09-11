<?php

namespace App\Http\Controllers;

use App\Models\Caja;
use App\Models\Compra;
use App\Models\User;
use App\Models\Venta;
use App\Services\ActivityLogger;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index()
    {
        return response()->json(User::select('id', 'name', 'email', 'role', 'activo', 'created_at')->orderBy('id', 'desc')->get());
    }

    public function resumenOperativo()
    {
        $desde = Carbon::now()->subDays(30);
        $usuarios = User::select('id', 'name', 'role', 'activo')->orderBy('name')->get();

        $ventas = Venta::query()->where('estado', 'completada')->where('created_at', '>=', $desde)
            ->selectRaw('usuario_id, COUNT(*) as cantidad, COALESCE(SUM(total), 0) as monto')->groupBy('usuario_id')->get()->keyBy('usuario_id');
        $compras = Compra::query()->where('fecha_recepcion', '>=', $desde)
            ->selectRaw('user_id, COUNT(*) as cantidad, COALESCE(SUM(total), 0) as monto')->groupBy('user_id')->get()->keyBy('user_id');
        $cierres = Caja::query()->where('estado', 'cerrada')->where('fecha_cierre', '>=', $desde)
            ->selectRaw('usuario_id, COUNT(*) as cantidad')->groupBy('usuario_id')->get()->keyBy('usuario_id');

        return response()->json([
            'desde' => $desde->toDateString(),
            'usuarios' => $usuarios->map(fn ($usuario) => [
                'id' => $usuario->id,
                'name' => $usuario->name,
                'role' => $usuario->role,
                'activo' => $usuario->activo,
                'ventas_cantidad' => (int) ($ventas[$usuario->id]->cantidad ?? 0),
                'ventas_monto' => (float) ($ventas[$usuario->id]->monto ?? 0),
                'compras_cantidad' => (int) ($compras[$usuario->id]->cantidad ?? 0),
                'compras_monto' => (float) ($compras[$usuario->id]->monto ?? 0),
                'cierres_cantidad' => (int) ($cierres[$usuario->id]->cantidad ?? 0),
            ])->values(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'role' => 'required|in:admin,cajero',
        ]);

        $usuario = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => $data['role'],
            'activo' => true,
        ]);

        ActivityLogger::log($request, 'user.created', User::class, $usuario->id, ['role' => $usuario->role]);
        return response()->json(['message' => 'Usuario registrado exitosamente.', 'usuario' => $usuario], 201);
    }

    public function toggleEstado(Request $request, $id)
    {
        $usuario = User::findOrFail($id);

        if ($usuario->id === $request->user()->id) {
            return response()->json(['message' => 'No puedes desactivar tu propia cuenta.'], 422);
        }

        if ($usuario->activo && $usuario->role === 'admin' && User::where('role', 'admin')->where('activo', true)->count() <= 1) {
            return response()->json(['message' => 'Debe permanecer al menos un administrador activo.'], 422);
        }

        $usuario->update(['activo' => !$usuario->activo]);
        ActivityLogger::log($request, 'user.status_changed', User::class, $usuario->id, ['activo' => $usuario->activo]);

        return response()->json([
            'message' => $usuario->activo ? 'Usuario activado correctamente.' : 'Usuario desactivado correctamente.',
            'usuario' => $usuario,
        ]);
    }
}