<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ReporteMailController;
use App\Http\Controllers\ProductoController;
use App\Http\Controllers\UsuarioController;

Route::post('/enviar-reporte', [ReporteMailController::class, 'enviarReporte']);

// Rutas completas CRUD para Productos (index, store, show, update, destroy)
Route::apiResource('productos', ProductoController::class);
Route::get('/usuarios', [UsuarioController::class, 'index']);
Route::post('/usuarios', [UsuarioController::class, 'store']);
Route::post('/login', [UsuarioController::class, 'login']);