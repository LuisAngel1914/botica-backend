<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ticket interno · Venta #{{ $venta->id }}</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            display: flex;
            min-height: 100vh;
            justify-content: center;
            background: #eef2f7;
            color: #111827;
            font-family: "Courier New", Courier, monospace;
            font-size: 11px;
            line-height: 1.35;
            padding: 16px;
        }
        .ticket {
            width: 72mm;
            overflow-wrap: anywhere;
            background: #fff;
            padding: 4mm;
            box-shadow: 0 12px 30px rgba(15, 23, 42, .12);
        }
        .logo {
            display: block;
            width: auto;
            max-width: 28mm;
            height: auto;
            max-height: 18mm;
            margin: 0 auto 2mm;
            object-fit: contain;
        }
        .business-name { font-size: 14px; font-weight: 800; }
        .document-label { margin: 2mm 0 1mm; font-size: 13px; font-weight: 800; letter-spacing: .04em; }
        .status-warning { border: 1px solid #111827; padding: 1.5mm 2mm; font-weight: 800; text-align: center; }
        .status-warning { margin: 0 0 2mm; }
        .muted { color: #4b5563; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .bold { font-weight: 800; }
        .divider { border-top: 1px dashed #111827; margin: 2.5mm 0; }
        .meta p + p { margin-top: .7mm; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: .8mm 0; vertical-align: top; }
        .item-detail { color: #4b5563; font-size: 9px; }
        .total { display: flex; justify-content: space-between; gap: 3mm; font-size: 14px; font-weight: 800; }
        .footer { margin-top: 2mm; }
        @media print {
            body { min-height: auto; width: 80mm; background: #fff; padding: 0; }
            .ticket { width: 72mm; box-shadow: none; }
            @page { size: 80mm auto; margin: 0; }
        }
    </style>
</head>
<body onload="window.print();">
    <main class="ticket">
        <header class="text-center">
            @if($configuracion->logo_url)
                <img class="logo" src="{{ $configuracion->logo_url }}" alt="Logo de {{ $configuracion->nombre_comercial }}">
            @endif
            <p class="business-name">{{ mb_strtoupper($configuracion->nombre_comercial) }}</p>
            @if($configuracion->razon_social)<p>{{ $configuracion->razon_social }}</p>@endif
            @if($configuracion->ruc)<p>RUC: {{ $configuracion->ruc }}</p>@endif
            @if($configuracion->direccion)<p>{{ $configuracion->direccion }}</p>@endif
            @if($configuracion->telefono)<p>Tel: {{ $configuracion->telefono }}</p>@endif
            @if($configuracion->email)<p>{{ $configuracion->email }}</p>@endif
            <p class="document-label">TICKET INTERNO DE VENTA</p>
        </header>

        <div class="divider"></div>

        @if($venta->estado === 'anulada')
            <p class="status-warning">VENTA ANULADA</p>
        @endif

        <section class="meta">
            <p><strong>Operación:</strong> {{ $venta->numero_comprobante ?: '#'.$venta->id }}</p>
            <p><strong>Fecha:</strong> {{ \Carbon\Carbon::parse($venta->created_at)->format('d/m/Y H:i') }}</p>
            <p><strong>Estado:</strong> {{ mb_strtoupper($venta->estado) }}</p>
            <p><strong>Cliente:</strong> {{ $venta->cliente ? $venta->cliente->nombre_razon_social : 'PÚBLICO GENERAL' }}</p>
            <p><strong>Documento:</strong> {{ $venta->cliente ? $venta->cliente->numero_documento : 'NO REGISTRADO' }}</p>
            <p><strong>Pago:</strong> {{ $venta->metodo_pago }}</p>
        </section>

        <div class="divider"></div>

        <table aria-label="Productos de la venta">
            <tbody>
                @foreach($venta->detalles as $detalle)
                <tr>
                    <td colspan="2" class="bold">{{ $detalle->producto->nombre ?? 'Producto' }}</td>
                </tr>
                <tr>
                    <td>{{ $detalle->cantidad }} × {{ $configuracion->simbolo_moneda }} {{ number_format($detalle->precio_unitario, 2) }}</td>
                    <td class="text-right">{{ $configuracion->simbolo_moneda }} {{ number_format($detalle->subtotal, 2) }}</td>
                </tr>
                @if($detalle->asignaciones->isNotEmpty())
                <tr>
                    <td colspan="2" class="item-detail">
                        Lote(s): {{ $detalle->asignaciones->map(fn ($asignacion) => ($asignacion->lote->numero_lote ?? 'N/D') . ' (' . $asignacion->cantidad . ')')->join(', ') }}
                    </td>
                </tr>
                @endif
                @endforeach
            </tbody>
        </table>

        <div class="divider"></div>

        <div class="total">
            <span>TOTAL</span>
            <span>{{ $configuracion->simbolo_moneda }} {{ number_format($venta->total, 2) }}</span>
        </div>

        <div class="divider"></div>

        <footer class="footer text-center">
            <p>{{ $configuracion->mensaje_ticket }}</p>
            <p class="muted">Documento interno de control. No reemplaza una boleta de venta autorizada.</p>
        </footer>
    </main>
</body>
</html>
