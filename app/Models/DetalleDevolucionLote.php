<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DetalleDevolucionLote extends Model
{
    protected $table = 'detalle_devolucion_lotes';

    protected $fillable = ['detalle_devolucion_venta_id', 'detalle_venta_lote_id', 'lote_id', 'cantidad'];

    public function detalleDevolucion()
    {
        return $this->belongsTo(DetalleDevolucionVenta::class, 'detalle_devolucion_venta_id');
    }

    public function detalleVentaLote()
    {
        return $this->belongsTo(DetalleVentaLote::class, 'detalle_venta_lote_id');
    }

    public function lote()
    {
        return $this->belongsTo(Lote::class);
    }
}
