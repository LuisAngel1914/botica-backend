<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Ticket #{{ $venta->numero_comprobante }}</title>
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
            <p>BOTICA L & L</p>
            <p>RUC: 20123456789</p>
            <p>Av. Principal #123 - Lima</p>
            <p>Tel: (01) 456-7890</p>
        </div>

        <div class="divider"></div>

        <div class="text-center bold">
            <p>{{ $venta->numero_comprobante }}</p>
            <p>Fecha: {{ \Carbon\Carbon::parse($venta->created_at)->format('d/m/Y H:i') }}</p>
        </div>

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
                    <td>{{ $detalle->cantidad }} x S/ {{ number_format($detalle->precio_unitario, 2) }}</td>
                    <td class="text-right">S/ {{ number_format($detalle->subtotal, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <div class="divider"></div>

        <div class="bold" style="display: flex; justify-content: space-between;">
            <span>TOTAL:</span>
            <span>S/ {{ number_format($venta->total, 2) }}</span>
        </div>

        <div class="divider"></div>

        <div class="text-center" style="margin-top: 8px;">
            <p>¡Gracias por su preferencia!</p>
            <p>Conserve su ticket para reclamos.</p>
        </div>
    </div>

</body>
</html>