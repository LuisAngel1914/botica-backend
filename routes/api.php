<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CajaController;
use App\Http\Controllers\CashClosureCorrectionController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\CompraController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\InventarioController;
use App\Http\Controllers\ProductoController;
use App\Http\Controllers\ReporteController;
use App\Http\Controllers\ReporteMailController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VentaController;

Route::get('/health', HealthController::class);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/productos', [ProductoController::class, 'index']);
    Route::get('/productos/buscar/{codigo}', [ProductoController::class, 'buscarPorCodigo']);
    Route::get('/clientes/buscar/{doc}', [ClienteController::class, 'buscarPorDocumento']);
    Route::get('/ventas', [VentaController::class, 'index']);
    Route::post('/ventas', [VentaController::class, 'store']);
    Route::get('/ventas/reporte-diario', [VentaController::class, 'reporteDiario']);
    Route::get('/ventas/{id}/ticket', [VentaController::class, 'ticket']);
    Route::get('/caja/estado', [CajaController::class, 'estadoActual']);
    Route::post('/caja/abrir', [CajaController::class, 'abrir']);
    Route::post('/caja/cerrar', [CajaController::class, 'cerrar']);
    Route::post('/chat', [ChatController::class, 'responder']);
    Route::post('/chat-auth', [ChatController::class, 'responder']);

    Route::middleware('role:admin')->group(function () {
        Route::apiResource('clientes', ClienteController::class)->except(['show', 'destroy']);
        Route::get('/clientes/{cliente}/resumen', [ClienteController::class, 'resumen']);
        Route::post('/ventas/{id}/anular', [VentaController::class, 'cancelar']);
        Route::get('/proveedores', [CompraController::class, 'proveedores']);
        Route::post('/proveedores', [CompraController::class, 'guardarProveedor']);
        Route::put('/proveedores/{proveedor}', [CompraController::class, 'guardarProveedor']);
        Route::get('/compras', [CompraController::class, 'index']);
        Route::post('/compras', [CompraController::class, 'store']);
        Route::post('/caja/{caja}/correcciones', [CashClosureCorrectionController::class, 'store']);
        Route::get('/caja/ultimo-cierre', [CajaController::class, 'ultimoCierre']);

        Route::get('/inventario', [InventarioController::class, 'index']);
        Route::post('/inventario/lote', [InventarioController::class, 'registrarLote']);
        Route::get('/inventario/por-vencer', [InventarioController::class, 'porVencer']);
        Route::get('/inventario/movimientos', [InventarioController::class, 'movimientos']);

        Route::get('/productos/alertas', [ProductoController::class, 'alertas']);
        Route::post('/productos', [ProductoController::class, 'store']);
        Route::put('/productos/{id}', [ProductoController::class, 'update']);
        Route::delete('/productos/{id}', [ProductoController::class, 'destroy']);

        Route::get('/actividad', [ActivityLogController::class, 'index']);

        Route::get('/usuarios', [UserController::class, 'index']);
        Route::get('/usuarios/resumen-operativo', [UserController::class, 'resumenOperativo']);
        Route::post('/usuarios', [UserController::class, 'store']);
        Route::patch('/usuarios/{id}/toggle', [UserController::class, 'toggleEstado']);

        Route::get('/reportes/resumen', [ReporteController::class, 'resumen']);
        Route::get('/reportes/dashboard', [ReporteController::class, 'dashboard']);
        Route::get('/reportes/pdf', [ReporteController::class, 'exportarPdf']);
        Route::get('/reportes/excel', [ReporteController::class, 'exportarExcel']);
        Route::post('/reportes/email', [ReporteController::class, 'enviarCorreo']);
        Route::post('/enviar-reporte', [ReporteMailController::class, 'enviarReporte']);
    });
});