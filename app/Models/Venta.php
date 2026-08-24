<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Venta extends Model
{
    protected $table = 'ventas';
    protected $fillable = [
        'usuario_id', 'producto_id', 'cantidad', 
        'precio_unitario', 'total', 'fecha_venta'
    ];
}