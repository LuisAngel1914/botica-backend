<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ReporteMailController;

Route::post('/enviar-reporte', [ReporteMailController::class, 'enviarReporte']);