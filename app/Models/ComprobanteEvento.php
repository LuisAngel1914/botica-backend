<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComprobanteEvento extends Model
{
    public $timestamps = false;

    protected $table = 'comprobante_eventos';

    protected $fillable = [
        'comprobante_id',
        'user_id',
        'evento',
        'estado_anterior',
        'estado_nuevo',
        'detalles',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'detalles' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function comprobante()
    {
        return $this->belongsTo(ComprobanteElectronico::class, 'comprobante_id');
    }
}
