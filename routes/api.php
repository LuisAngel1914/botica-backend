<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ReporteMailController;
use App\Http\Controllers\ProductoController;
use App\Http\Controllers\VentaController;
use App\Http\Controllers\ReporteController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\CajaController;
use App\Http\Controllers\InventarioController;
use App\Http\Controllers\UserController;

/*
|--------------------------------------------------------------------------
| Rutas Públicas / POS Operativo
|--------------------------------------------------------------------------
*/
Route::post('/login', [AuthController::class, 'login']);

// Operaciones del POS (Catálogo, Búsqueda de clientes, Emisión y Ticket)
Route::get('/productos', [ProductoController::class, 'index']);
Route::get('/productos/buscar/{codigo}', [ProductoController::class, 'buscarPorCodigo']);
Route::get('/clientes/buscar/{doc}', [ClienteController::class, 'buscarPorDocumento']);

// Historial, Emisión y Anulación de Ventas
Route::get('/ventas', [VentaController::class, 'index']);
Route::post('/ventas', [VentaController::class, 'store']);
Route::post('/ventas/{id}/anular', [VentaController::class, 'cancelar']);
Route::get('/ventas/reporte-diario', [VentaController::class, 'reporteDiario']);
Route::get('/ventas/{id}/ticket', [VentaController::class, 'ticket']);

// Exportación y Resumen de Reportes
Route::get('/reportes/resumen', [ReporteController::class, 'resumen']);
Route::get('/reportes/pdf', [ReporteController::class, 'exportarPdf']);
Route::get('/reportes/excel', [ReporteController::class, 'exportarExcel']);

// Control de Caja
Route::get('/caja/estado', [CajaController::class, 'estadoActual']);
Route::post('/caja/abrir', [CajaController::class, 'abrir']);
Route::post('/caja/cerrar', [CajaController::class, 'cerrar']);

// Control de Inventario
Route::get('/inventario', [InventarioController::class, 'index']);
Route::post('/inventario/lote', [InventarioController::class, 'registrarLote']);
Route::get('/inventario/por-vencer', [InventarioController::class, 'porVencer']);

// Gestión Administrativa de Productos
Route::get('/productos/alertas', [ProductoController::class, 'alertas']);
Route::post('/productos', [ProductoController::class, 'store']);
Route::put('/productos/{id}', [ProductoController::class, 'update']);
Route::delete('/productos/{id}', [ProductoController::class, 'destroy']);

// Gestión de Usuarios (Conectado con UsuariosView.vue)
Route::get('/usuarios', [UserController::class, 'index']);
Route::post('/usuarios', [UserController::class, 'store']);
Route::patch('/usuarios/{id}/toggle', [UserController::class, 'toggleEstado']);


/*
|--------------------------------------------------------------------------
| Rutas Protegidas (Requieren Token Bearer Sanctum)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    
    Route::post('/logout', [AuthController::class, 'logout']);

    // Reportes & Alertas
    Route::get('/reportes/dashboard', [ReporteController::class, 'dashboard']);
    Route::post('/reportes/email', [ReporteController::class, 'enviarCorreo']);
    Route::post('/enviar-reporte', [ReporteMailController::class, 'enviarReporte']);

    // Clientes CRUD
    Route::apiResource('clientes', ClienteController::class);
});