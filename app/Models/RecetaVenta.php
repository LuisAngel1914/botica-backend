<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecetaVenta extends Model
{
    protected $table = 'recetas_venta';

    protected $fillable = [
        'venta_id',
        'cliente_id',
        'verificada_por',
        'paciente_nombre',
        'paciente_documento',
        'prescriptor_nombre',
        'prescriptor_colegiatura',
        'fecha_emision',
        'tipo',
        'referencia',
        'verificada_at',
    ];

    protected $casts = [
        'fecha_emision' => 'date',
        'verificada_at' => 'datetime',
    ];
}
