<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Producto extends Model
{
    use HasFactory;

    protected $table = 'productos';
    public $timestamps = false;

    protected $fillable = [
        'codigo_barras',
        'nombre',
        'principio_activo',
        'presentacion',
        'categoria',
        'precio_compra',
        'precio_venta',
        'stock_actual',
        'stock_minimo',
        'fecha_vencimiento',
        'requiere_receta',
        'imagen_url',
        'estado'
    ];

    // Casteo para forzar tipos de datos correctos hacia Vue
    protected $casts = [
        'requiere_receta' => 'boolean',
        'precio_venta'    => 'float',
        'precio_compra'   => 'float',
        'stock_actual'    => 'integer',
    ];

    // Relación con el modelo Lote
    public function lotes()
    {
        return $this->hasMany(Lote::class);
    }
}