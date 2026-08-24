<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Producto extends Model
{
    protected $table = 'productos';
    public $timestamps = false;
    protected $fillable = [
        'codigo_barras', 'nombre', 'categoria', 
        'precio_venta', 'stock_actual', 'stock_minimo', 
        'fecha_vencimiento', 'imagen_url', 'estado'
    ];
}