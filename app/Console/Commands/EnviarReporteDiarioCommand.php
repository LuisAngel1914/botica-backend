<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Venta;
use App\Mail\ReporteDiarioMail;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;

class EnviarReporteDiarioCommand extends Command
{
    protected $signature = 'botica:enviar-reporte {email=admin@botica.com}';
    protected $description = 'Envía el reporte diario de ventas por correo electrónico';

    public function handle()
    {
        $hoy = Carbon::today();
        $emailDestino = $this->argument('email');

        $ventasHoy = Venta::with('cliente')
            ->whereDate('created_at', $hoy)
            ->where('estado', 'completada')
            ->get();

        $datosReporte = [
            'total_general'   => $ventasHoy->sum('total'),
            'cantidad_ventas' => $ventasHoy->count(),
            'ventas'          => $ventasHoy
        ];

        Mail::to($emailDestino)->send(new ReporteDiarioMail($datosReporte));

        $this->info("Reporte diario enviado exitosamente a: {$emailDestino}");
        return Command::SUCCESS;
    }
}