<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: Arial, sans-serif; color: #333; margin: 0; padding: 20px; }
        .resumen { background: #f4f4f4; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #2c3e50; color: white; }
        .monto { text-align: right; }
    </style>
</head>
<body>
    <h2>Resumen Diario de Ventas</h2>
    
    <div class="resumen">
        <p><strong>Total General:</strong> S/ {{ number_format($reporte['total_general'] ?? 0, 2) }}</p>
        <p><strong>Comprobantes Emitidos:</strong> {{ $reporte['cantidad_ventas'] ?? 0 }}</p>
    </div>

    <h3>Desglose de Ventas</h3>
    <table>
        <thead>
            <tr>
                <th>Comprobante</th>
                <th>Cliente</th>
                <th>Método Pago</th>
                <th class="monto">Total</th>
            </tr>
        </thead>
        <tbody>
            @if(isset($reporte['ventas']) && count($reporte['ventas']) > 0)
                @foreach($reporte['ventas'] as $venta)
                <tr>
                    <td>{{ $venta->numero_comprobante }}</td>
                    <td>{{ $venta->cliente->nombre_razon_social ?? 'Público General' }}</td>
                    <td>{{ $venta->metodo_pago }}</td>
                    <td class="monto">S/ {{ number_format($venta->total, 2) }}</td>
                </tr>
                @endforeach
            @else
                <tr>
                    <td colspan="4" style="text-align: center;">No hay ventas registradas en el día.</td>
                </tr>
            @endif
        </tbody>
    </table>
</body>
</html>