<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Process extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    protected $fillable = [
        'contract_id',
        'client_id',
        'service_type_id',
        'abogado_lider_id',
        'apoderado_id',
        'coordinador_id',
        'codigo',
        'titulo',
        'descripcion',
        'resumen_ia',
        'resumen_ia_generado_at',
        'estado',
        'fecha_apertura',
        'fecha_cierre',
    ];

    protected $casts = [
        'fecha_apertura' => 'date',
        'fecha_cierre' => 'date',
        'resumen_ia_generado_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }

    public function abogadoLider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'abogado_lider_id');
    }

    public function apoderado(): BelongsTo
    {
        return $this->belongsTo(User::class, 'apoderado_id');
    }

    public function coordinador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coordinador_id');
    }

    public function stages(): HasMany
    {
        return $this->hasMany(ProcessStage::class)->orderBy('orden');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }

    public function emailIngestions(): HasMany
    {
        return $this->hasMany(EmailIngestion::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['codigo', 'titulo', 'estado', 'abogado_lider_id', 'apoderado_id', 'fecha_cierre'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
