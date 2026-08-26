<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Lote extends Model
{
    use HasFactory;

    protected $table = 'lotes';

    protected $fillable = [
        'producto_id',
        'numero_lote',
        'stock',
        'fecha_vencimiento'
    ];

    public function producto()
    {
        return $this->belongsTo(Producto::class);
    }
}