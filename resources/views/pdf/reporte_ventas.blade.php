<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: sans-serif; font-size: 11px; color: #333; }
        .header { text-align: center; margin-bottom: 20px; }
        .table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .table th, .table td { border: 1px solid #ddd; padding: 6px 8px; text-align: left; }
        .table th { background-color: #f8fafc; font-weight: bold; color: #1e293b; }
        .text-right { text-align: right; }
        .total-box { margin-top: 15px; text-align: right; font-size: 13px; font-weight: bold; color: #2563eb; }
    </style>
</head>
<body>
    <div class="header">
        <h2 style="margin: 0;">REPORTE DE VENTAS - BOTICA</h2>
        <p style="margin: 4px 0 0 0; color: #64748b;">Generado el: {{ date('d/m/Y H:i A') }}</p>
    </div>

    <table class="table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Fecha</th>
                <th>Cliente</th>
                <th>Método Pago</th>
                <th>Estado</th>
                <th class="text-right">Bruto</th>
                <th class="text-right">Devuelto</th>
                <th class="text-right">Neto</th>
            </tr>
        </thead>
        <tbody>
            @foreach($ventas as $v)
            <tr>
                <td>#{{ $v->id }}</td>
                <td>{{ $v->created_at->format('d/m/Y H:i') }}</td>
                <td>{{ $v->cliente->nombre_razon_social ?? $v->cliente->nombre ?? 'Cliente Eventual' }}</td>
                <td>{{ $v->metodo_pago }}</td>
                <td>{{ ucfirst($v->estado ?? 'completada') }}</td>
                <td class="text-right">S/ {{ number_format($v->total, 2) }}</td>
                <td class="text-right">S/ {{ number_format($v->devoluciones_total, 2) }}</td>
                <td class="text-right">S/ {{ number_format($v->total_neto, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="total-box">
        Ventas brutas: S/ {{ number_format($resumen['ventas_brutas'], 2) }}<br>
        Devoluciones: S/ {{ number_format($resumen['devoluciones'], 2) }}<br>
        Venta neta: S/ {{ number_format($resumen['ventas_netas'], 2) }}<br>
        Ventas anuladas: {{ $resumen['ventas_anuladas'] }}
    </div>
</body>
</html>