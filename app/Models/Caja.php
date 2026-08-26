<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Caja extends Model
{
    use HasFactory;

    protected $fillable = [
        'usuario_id',
        'monto_inicial',
        'monto_final',
        'total_ventas_efectivo',
        'diferencia',
        'estado',
        'fecha_apertura',
        'fecha_cierre',
    ];

    public function usuario()
    {
        return $this->belongsTo(User::class);
    }
}