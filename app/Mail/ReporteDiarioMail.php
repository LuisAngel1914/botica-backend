<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ReporteDiarioMail extends Mailable
{
    use Queueable, SerializesModels;

    public $reporte;

    public function __construct($reporte)
    {
        $this->reporte = $reporte;
    }

    public function build()
    {
        return $this->subject('Reporte Diario de Ventas - Botica')
                    ->view('emails.reporte_diario');
    }
}