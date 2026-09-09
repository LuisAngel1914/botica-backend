<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CashClosureCorrection extends Model {
 protected $fillable=['caja_id','user_id','monto_final_corregido','motivo'];
 public function caja(){ return $this->belongsTo(Caja::class); }
 public function user(){ return $this->belongsTo(User::class); }
}