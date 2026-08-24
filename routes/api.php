<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ReporteMailController;
use App\Http\Controllers\ProductoController;

Route::post('/enviar-reporte', [ReporteMailController::class, 'enviarReporte']);

// Rutas completas CRUD para Productos (index, store, show, update, destroy)
Route::apiResource('productos', ProductoController::class);