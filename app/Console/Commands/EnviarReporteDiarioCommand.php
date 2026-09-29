<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Venta;
use App\Mail\ReporteDiarioMail;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;
use App\Models\Configuracion;

class EnviarReporteDiarioCommand extends Command
{
    protected $signature = 'botica:enviar-reporte {email?}';
    protected $description = 'Envía el reporte diario de ventas por correo electrónico';

    public function handle()
    {
        $hoy = Carbon::today();
        $configuracion = Configuracion::actual();
        $emailDestino = $this->argument('email') ?: $configuracion->email;

        if (blank($emailDestino)) {
            $this->error('Configura un correo de contacto antes de programar el reporte diario.');
            return Command::FAILURE;
        }

        $ventasHoy = Venta::with('cliente')
            ->whereDate('created_at', $hoy)
            ->where('estado', 'completada')
            ->get();

        $datosReporte = [
            'total_general'   => $ventasHoy->sum('total'),
            'cantidad_ventas' => $ventasHoy->count(),
            'ventas'          => $ventasHoy,
            'configuracion'   => $configuracion,
        ];

        Mail::to($emailDestino)->send(new ReporteDiarioMail($datosReporte));

        $this->info("Reporte diario enviado exitosamente a: {$emailDestino}");
        return Command::SUCCESS;
    }
}
