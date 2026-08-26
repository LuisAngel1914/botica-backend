<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cliente extends Model
{
    protected $table = 'clientes';
    
    // Desactivar marcas de tiempo automáticas para evitar incompatibilidad con MySQL
    public $timestamps = false;

    protected $fillable = [
        'tipo_documento',
        'numero_documento',
        'nombre_razon_social',
        'direccion',
        'telefono',
        'email',
        'estado'
    ];
}