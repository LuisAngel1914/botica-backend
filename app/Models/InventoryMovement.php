<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryMovement extends Model
{
    protected $fillable = ['producto_id', 'lote_id', 'user_id', 'tipo', 'cantidad', 'referencia', 'motivo'];

    public function producto()
    {
        return $this->belongsTo(Producto::class);
    }

    public function lote()
    {
        return $this->belongsTo(Lote::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}