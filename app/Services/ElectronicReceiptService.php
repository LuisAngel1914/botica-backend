<?php

namespace App\Services;

use App\Models\ComprobanteElectronico;
use App\Models\ComprobanteEvento;
use App\Models\Configuracion;
use App\Models\Venta;
use Illuminate\Validation\ValidationException;

class ElectronicReceiptService
{
    public function createDemoForSale(Venta $venta, ?int $userId): ComprobanteElectronico
    {
        if ($venta->comprobante) {
            return $venta->comprobante;
        }

        $configuracion = Configuracion::actual();

        if ($configuracion->modo_emision_comprobantes !== 'demo') {
            throw ValidationException::withMessages([
                'comprobante' => 'La emisión productiva todavía no está configurada. Activa el modo demostración o conecta un proveedor autorizado.',
            ]);
        }

        $venta->loadMissing(['cliente', 'detalles.producto']);
        $serie = strtoupper($configuracion->serie_comprobante ?: 'B001');
        $lastCorrelative = ComprobanteElectronico::where('serie', $serie)
            ->orderByDesc('correlativo')
            ->lockForUpdate()
            ->value('correlativo');
        $correlativo = ((int) $lastCorrelative) + 1;
        $numero = $serie . '-' . str_pad((string) $correlativo, 8, '0', STR_PAD_LEFT);

        $payload = [
            'version' => 1,
            'entorno' => 'demo',
            'tipo_documento' => '03',
            'numero' => $numero,
            'fecha_emision' => now()->toIso8601String(),
            'emisor' => [
                'ruc' => $configuracion->ruc,
                'razon_social' => $configuracion->razon_social,
                'nombre_comercial' => $configuracion->nombre_comercial,
                'direccion' => $configuracion->direccion,
                'regimen_tributario' => $configuracion->regimen_tributario,
            ],
            'cliente' => [
                'tipo_documento' => $venta->cliente?->tipo_documento,
                'numero_documento' => $venta->cliente?->numero_documento,
                'nombre' => $venta->cliente?->nombre_razon_social ?? $venta->cliente?->nombre ?? 'CLIENTE VARIOS',
            ],
            'moneda' => $configuracion->moneda,
            'items' => $venta->detalles->map(fn ($detalle) => [
                'producto_id' => $detalle->producto_id,
                'descripcion' => $detalle->producto?->nombre ?? 'Producto',
                'cantidad' => (int) $detalle->cantidad,
                'precio_unitario' => (float) $detalle->precio_unitario,
                'subtotal' => (float) $detalle->subtotal,
            ])->values()->all(),
            'total' => (float) $venta->total,
            'leyenda' => 'Representación demostrativa sin validez tributaria. No fue enviada a SUNAT.',
        ];

        $encodedPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);

        $comprobante = ComprobanteElectronico::create([
            'venta_id' => $venta->id,
            'tipo' => 'boleta',
            'modo' => 'demo',
            'serie' => $serie,
            'correlativo' => $correlativo,
            'numero' => $numero,
            'estado' => 'simulado',
            'moneda' => $configuracion->moneda,
            'total' => $venta->total,
            'payload' => $payload,
            'hash' => hash('sha256', $encodedPayload),
        ]);

        $this->recordEvent($comprobante, $userId, 'demo.generated', null, 'simulado', [
            'mensaje' => 'Comprobante generado localmente en modo demostración; no se transmitió a SUNAT.',
        ]);

        $venta->update(['numero_comprobante' => $numero]);

        return $comprobante->load('eventos');
    }

    public function markDemoCancelled(Venta $venta, ?int $userId, string $reason): void
    {
        $comprobante = $venta->comprobante;

        if (!$comprobante || $comprobante->modo !== 'demo' || $comprobante->estado === 'anulado_demo') {
            return;
        }

        $previousStatus = $comprobante->estado;
        $comprobante->update(['estado' => 'anulado_demo']);

        $this->recordEvent($comprobante, $userId, 'demo.cancelled', $previousStatus, 'anulado_demo', [
            'motivo' => $reason,
            'advertencia' => 'En producción una boleta aceptada requiere nota de crédito o comunicación de baja según corresponda.',
        ]);
    }

    private function recordEvent(
        ComprobanteElectronico $comprobante,
        ?int $userId,
        string $event,
        ?string $previousStatus,
        string $newStatus,
        array $details = []
    ): void {
        ComprobanteEvento::create([
            'comprobante_id' => $comprobante->id,
            'user_id' => $userId,
            'evento' => $event,
            'estado_anterior' => $previousStatus,
            'estado_nuevo' => $newStatus,
            'detalles' => $details,
            'created_at' => now(),
        ]);
    }
}
