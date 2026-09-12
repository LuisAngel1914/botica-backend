<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DetalleDevolucionVenta extends Model
{
    protected $table = 'detalle_devoluciones_venta';

    protected $fillable = ['devolucion_venta_id', 'detalle_venta_id', 'cantidad', 'precio_unitario', 'subtotal'];

    public function devolucion()
    {
        return $this->belongsTo(DevolucionVenta::class, 'devolucion_venta_id');
    }

    public function detalleVenta()
    {
        return $this->belongsTo(DetalleVenta::class, 'detalle_venta_id');
    }

    public function asignaciones()
    {
        return $this->hasMany(DetalleDevolucionLote::class, 'detalle_devolucion_venta_id');
    }
}
