<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Configuracion extends Model
{
    protected $table = 'configuraciones';

    protected $fillable = [
        'nombre_comercial',
        'razon_social',
        'ruc',
        'direccion',
        'telefono',
        'email',
        'logo_url',
        'moneda',
        'simbolo_moneda',
        'regimen_tributario',
        'modo_emision_comprobantes',
        'tipo_comprobante_predeterminado',
        'impuesto_nombre',
        'impuesto_porcentaje',
        'serie_comprobante',
        'stock_minimo_default',
        'dias_alerta_vencimiento',
        'mensaje_ticket',
    ];

    protected function casts(): array
    {
        return [
            'impuesto_porcentaje' => 'float',
            'stock_minimo_default' => 'integer',
            'dias_alerta_vencimiento' => 'integer',
        ];
    }

    public static function actual(): self
    {
        return static::query()->firstOrCreate(['id' => 1], [
            'nombre_comercial' => 'Botica L y L',
            'moneda' => 'PEN',
            'simbolo_moneda' => 'S/',
            'regimen_tributario' => 'NRUS',
            'modo_emision_comprobantes' => 'demo',
            'tipo_comprobante_predeterminado' => 'boleta',
            'impuesto_nombre' => 'IGV',
            'impuesto_porcentaje' => 18,
            'serie_comprobante' => 'B001',
            'stock_minimo_default' => 5,
            'dias_alerta_vencimiento' => 60,
            'mensaje_ticket' => 'Gracias por su preferencia. Conserve su ticket para reclamos.',
        ]);
    }

    public function publicData(): array
    {
        return [
            'nombre_comercial' => $this->nombre_comercial,
            'razon_social' => $this->razon_social,
            'ruc' => $this->ruc,
            'direccion' => $this->direccion,
            'telefono' => $this->telefono,
            'email' => $this->email,
            'logo_url' => $this->logo_url,
            'moneda' => $this->moneda,
            'simbolo_moneda' => $this->simbolo_moneda,
            'regimen_tributario' => $this->regimen_tributario,
            'modo_emision_comprobantes' => $this->modo_emision_comprobantes,
            'tipo_comprobante_predeterminado' => $this->tipo_comprobante_predeterminado,
            'impuesto_nombre' => $this->impuesto_nombre,
            'impuesto_porcentaje' => $this->impuesto_porcentaje,
            'serie_comprobante' => $this->serie_comprobante,
            'stock_minimo_default' => $this->stock_minimo_default,
            'dias_alerta_vencimiento' => $this->dias_alerta_vencimiento,
            'mensaje_ticket' => $this->mensaje_ticket,
            'configuracion_completa' => $this->missingRequiredFields() === [],
            'campos_pendientes' => $this->missingRequiredFields(),
        ];
    }

    public function missingRequiredFields(): array
    {
        $required = [
            'nombre_comercial' => 'Nombre comercial',
            'razon_social' => 'Razón social',
            'ruc' => 'RUC',
            'direccion' => 'Dirección',
            'telefono' => 'Teléfono',
            'email' => 'Correo de contacto',
        ];

        return collect($required)
            ->filter(fn (string $label, string $field) => blank($this->{$field}))
            ->values()
            ->all();
    }
}
