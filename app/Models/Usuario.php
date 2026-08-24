<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Usuario extends Model
{
    protected $table = 'usuarios';
    protected $fillable = ['rol_id', 'nombre_completo', 'email', 'password', 'estado'];
    protected $hidden = ['password'];
}