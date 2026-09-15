<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssistantInteraction extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'intent',
        'source',
        'response_code',
        'helpful',
        'feedback_at',
    ];

    protected function casts(): array
    {
        return [
            'helpful' => 'boolean',
            'feedback_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
