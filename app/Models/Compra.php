<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Compra extends Model
{
    protected $table = 'compras';
    protected $fillable = ['proveedor_id', 'user_id', 'numero_documento', 'total', 'fecha_recepcion'];
    protected $casts = ['fecha_recepcion' => 'datetime'];

    public function proveedor() { return $this->belongsTo(Proveedor::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function detalles() { return $this->hasMany(DetalleCompra::class); }
}