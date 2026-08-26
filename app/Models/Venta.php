<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Venta extends Model
{
    protected $table = 'ventas';

    protected $fillable = [
        'usuario_id',
        'cliente_id',
        'numero_comprobante',
        'total',
        'metodo_pago',
        'estado',
    ];

    // Relación con el cliente asignado a la venta
    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }

    // Relación con los detalles de la venta
    public function detalles()
    {
        return $this->hasMany(DetalleVenta::class, 'venta_id');
    }
}