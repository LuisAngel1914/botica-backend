<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComprobanteElectronico extends Model
{
    protected $table = 'comprobantes_electronicos';

    protected $fillable = [
        'venta_id',
        'tipo',
        'modo',
        'serie',
        'correlativo',
        'numero',
        'estado',
        'moneda',
        'total',
        'payload',
        'hash',
        'enviado_at',
        'aceptado_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'correlativo' => 'integer',
            'total' => 'decimal:2',
            'payload' => 'array',
            'enviado_at' => 'datetime',
            'aceptado_at' => 'datetime',
        ];
    }

    public function venta()
    {
        return $this->belongsTo(Venta::class, 'venta_id');
    }

    public function eventos()
    {
        return $this->hasMany(ComprobanteEvento::class, 'comprobante_id')->orderBy('id');
    }
}
