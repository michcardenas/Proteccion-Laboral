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
        'visit_id',
        'payment_id',
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
        'texto_extraido',
        'texto_extraido_at',
        'resumen_ia',
        'resumen_ia_at',
        'drive_file_id',
        'drive_modified_at',
    ];

    protected $casts = [
        'generado_por_ia' => 'boolean',
        'visible_cliente' => 'boolean',
        'texto_extraido_at' => 'datetime',
        'resumen_ia_at' => 'datetime',
        'drive_modified_at' => 'datetime',
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

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subido_por');
    }

    /**
     * Si este cliente puede abrir el documento desde su portal.
     *
     * Tiene que ser suyo Y estar compartido con el. Antes bastaba con que fuera
     * de uno de sus procesos, asi que cambiando el id en la URL el cliente
     * descargaba adjuntos de correo y borradores internos que nadie le enseño.
     *
     * El acta de una visita sigue a la visita: se ve mientras la visita sea
     * visible, sin importar lo que diga su propia casilla.
     */
    public function visibleEnPortalPara(Client $client): bool
    {
        $esSuyo = $this->process_id !== null
            ? $this->process?->client_id === $client->id
            : $this->client_id === $client->id;

        if (! $esSuyo) {
            return false;
        }

        return $this->visit_id !== null
            ? (bool) $this->visit?->visible_cliente
            : $this->visible_cliente;
    }
}
