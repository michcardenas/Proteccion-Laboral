<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Visit extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'process_id',
        'client_id',
        'registrada_por',
        'tipo',
        'fecha',
        'titulo',
        'descripcion',
        'visible_cliente',
    ];

    protected $casts = [
        'fecha' => 'date',
        'visible_cliente' => 'boolean',
    ];

    public const TIPOS = ['presencial', 'virtual', 'telefonica', 'otro'];

    public function process(): BelongsTo
    {
        return $this->belongsTo(Process::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function registradaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrada_por');
    }

    public function asistentes(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'visit_user')->withTimestamps();
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }
}
