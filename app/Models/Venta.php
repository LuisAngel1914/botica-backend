<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Venta extends Model
{
    protected $table = 'ventas';

    // Laravel gestionará created_at automáticamente (desactivamos updated_at)
    const UPDATED_AT = null;

    protected $fillable = [
        'usuario_id',
        'cliente_id',
        'total',
        'metodo_pago',
        'estado'
    ];
}