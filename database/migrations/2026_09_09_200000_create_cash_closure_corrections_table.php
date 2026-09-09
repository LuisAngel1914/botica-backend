<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { Schema::create('cash_closure_corrections', function (Blueprint $table) {
  $table->id(); $table->foreignId('caja_id')->constrained('cajas')->cascadeOnDelete(); $table->foreignId('user_id')->constrained('users');
  $table->decimal('monto_final_corregido', 10, 2); $table->text('motivo'); $table->timestamps();
  $table->index(['caja_id','created_at']);
 });}
 public function down(): void { Schema::dropIfExists('cash_closure_corrections'); }
};