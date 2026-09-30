<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Boleta demostrativa {{ $venta->numero_comprobante }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: monospace;
            font-size: 12px;
        }

        body {
            width: 100%;
            display: flex;
            justify-content: center;
            background-color: #f3f4f6;
            padding: 10px;
        }

        .ticket {
            width: 260px;
            background: #fff;
            padding: 10px;
        }

        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .bold { font-weight: bold; }

        .demo-banner {
            border: 2px solid #000;
            margin: 8px 0;
            padding: 6px;
            text-align: center;
            font-weight: bold;
        }

        .small { font-size: 9px; line-height: 1.35; }

        .divider {
            border-top: 1px dashed #000;
            margin: 6px 0;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        td { padding: 2px 0; }

        @media print {
            body {
                background: none;
                padding: 0;
            }
            .ticket {
                width: 100%;
                padding: 0;
            }
            @page {
                margin: 0;
            }
        }
    </style>
</head>
<body onload="window.print();">

    <div class="ticket">
        <div class="text-center bold">
            @if($configuracion->logo_url)
                <img src="{{ $configuracion->logo_url }}" alt="Logo" style="max-width: 80px; max-height: 50px; margin-bottom: 5px;">
            @endif
            <p>{{ mb_strtoupper($configuracion->nombre_comercial) }}</p>
            @if($configuracion->razon_social)<p>{{ $configuracion->razon_social }}</p>@endif
            @if($configuracion->ruc)<p>RUC: {{ $configuracion->ruc }}</p>@endif
            @if($configuracion->direccion)<p>{{ $configuracion->direccion }}</p>@endif
            @if($configuracion->telefono)<p>Tel: {{ $configuracion->telefono }}</p>@endif
        </div>

        <div class="divider"></div>

        <div class="text-center bold">
            <p>BOLETA DE VENTA ELECTRÓNICA</p>
            <p>{{ $venta->comprobante?->numero ?? $venta->numero_comprobante }}</p>
            <p>Fecha: {{ \Carbon\Carbon::parse($venta->created_at)->format('d/m/Y H:i') }}</p>
        </div>

        @if($venta->comprobante?->modo === 'demo')
            <div class="demo-banner">
                MODO PRUEBAS<br>
                SIN VALIDEZ TRIBUTARIA<br>
                NO ENVIADO A SUNAT
            </div>
        @endif

        <div class="divider"></div>

        <div>
            <p><strong>Cliente:</strong> {{ $venta->cliente ? $venta->cliente->nombre_razon_social : 'PÚBLICO GENERAL' }}</p>
            <p><strong>Doc:</strong> {{ $venta->cliente ? $venta->cliente->numero_documento : '00000000' }}</p>
            <p><strong>Pago:</strong> {{ $venta->metodo_pago }}</p>
        </div>

        <div class="divider"></div>

        <table>
            <tbody>
                @foreach($venta->detalles as $detalle)
                <tr>
                    <td colspan="3" class="bold">{{ $detalle->producto->nombre ?? 'Producto' }}</td>
                </tr>
                <tr>
                    <td>{{ $detalle->cantidad }} x {{ $configuracion->simbolo_moneda }} {{ number_format($detalle->precio_unitario, 2) }}</td>
                    <td class="text-right">{{ $configuracion->simbolo_moneda }} {{ number_format($detalle->subtotal, 2) }}</td>
                </tr>
                @if($detalle->asignaciones->isNotEmpty())
                <tr>
                    <td colspan="2" style="font-size: 10px;">
                        Lote(s): {{ $detalle->asignaciones->map(fn ($asignacion) => ($asignacion->lote->numero_lote ?? 'N/D') . ' (' . $asignacion->cantidad . ')')->join(', ') }}
                    </td>
                </tr>
                @endif
                @endforeach
            </tbody>
        </table>

        <div class="divider"></div>

        <div class="bold" style="display: flex; justify-content: space-between;">
            <span>TOTAL:</span>
            <span>{{ $configuracion->simbolo_moneda }} {{ number_format($venta->total, 2) }}</span>
        </div>

        <div class="small" style="margin-top: 6px;">
            <p>Régimen: {{ $configuracion->regimen_tributario ?? 'NRUS' }}</p>
            <p>Estado: {{ mb_strtoupper(str_replace('_', ' ', $venta->comprobante?->estado ?? 'sin comprobante')) }}</p>
            @if($venta->comprobante?->hash)
                <p>Huella: {{ substr($venta->comprobante->hash, 0, 16) }}</p>
            @endif
        </div>

        <div class="divider"></div>

        <div class="text-center" style="margin-top: 8px;">
            <p>{{ $configuracion->mensaje_ticket }}</p>
            @if($venta->comprobante?->modo === 'demo')
                <p class="small" style="margin-top: 6px;">Representación demostrativa para evaluación de la propuesta. La activación productiva requerirá credenciales y configuración oficial.</p>
            @endif
        </div>
    </div>

</body>
</html>
