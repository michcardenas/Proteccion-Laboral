<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AiGeneration extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'contexto_tipo',
        'contexto_id',
        'proveedor',
        'modelo',
        'prompt',
        'respuesta',
        'tokens_in',
        'tokens_out',
        'costo_usd',
        'estado',
        'error_mensaje',
    ];

    protected $casts = [
        'costo_usd' => 'decimal:6',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function contexto(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'contexto_tipo', 'contexto_id');
    }
}
