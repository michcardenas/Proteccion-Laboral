<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Document extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'process_id',
        'email_ingestion_id',
        'process_stage_id',
        'task_id',
        'client_id',
        'nombre',
        'ruta',
        'disco',
        'tipo',
        'mime',
        'tamano_bytes',
        'generado_por_ia',
        'version',
        'subido_por',
        'visible_cliente',
    ];

    protected $casts = [
        'generado_por_ia' => 'boolean',
        'visible_cliente' => 'boolean',
    ];

    public function process(): BelongsTo
    {
        return $this->belongsTo(Process::class);
    }

    public function emailIngestion(): BelongsTo
    {
        return $this->belongsTo(EmailIngestion::class);
    }

    public function processStage(): BelongsTo
    {
        return $this->belongsTo(ProcessStage::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subido_por');
    }
}
