<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DevolucionVenta extends Model
{
    protected $table = 'devoluciones_venta';

    protected $fillable = ['venta_id', 'user_id', 'total', 'motivo'];

    public function venta()
    {
        return $this->belongsTo(Venta::class);
    }

    public function detalles()
    {
        return $this->hasMany(DetalleDevolucionVenta::class, 'devolucion_venta_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
