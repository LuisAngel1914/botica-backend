<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DetalleVentaLote extends Model
{
    protected $table = 'detalle_venta_lotes';

    protected $fillable = [
        'detalle_venta_id',
        'lote_id',
        'cantidad',
    ];

    public function detalleVenta()
    {
        return $this->belongsTo(DetalleVenta::class, 'detalle_venta_id');
    }

    public function lote()
    {
        return $this->belongsTo(Lote::class);
    }
}
