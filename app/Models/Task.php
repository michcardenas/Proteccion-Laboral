<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Task extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'process_id',
        'process_stage_id',
        'titulo',
        'descripcion',
        'asignado_a',
        'creado_por',
        'prioridad',
        'estado',
        'fecha_limite',
        'completada_at',
    ];

    protected $casts = [
        'fecha_limite' => 'date',
        'completada_at' => 'datetime',
    ];

    public function process(): BelongsTo
    {
        return $this->belongsTo(Process::class);
    }

    public function processStage(): BelongsTo
    {
        return $this->belongsTo(ProcessStage::class);
    }

    public function asignado(): BelongsTo
    {
        return $this->belongsTo(User::class, 'asignado_a');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['titulo', 'estado', 'prioridad', 'asignado_a', 'fecha_limite'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
