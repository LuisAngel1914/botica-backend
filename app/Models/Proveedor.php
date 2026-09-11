<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Proveedor extends Model
{
    protected $table = 'proveedores';
    protected $fillable = ['nombre', 'ruc', 'contacto', 'telefono', 'email', 'direccion', 'activo'];

    public function compras()
    {
        return $this->hasMany(Compra::class);
    }
}