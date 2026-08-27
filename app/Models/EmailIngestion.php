<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailIngestion extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_CLASSIFIED = 'classified';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_NEEDS_REVIEW = 'needs_review';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DISCARDED = 'descartado';

    protected $fillable = [
        'integration_token_id',
        'message_id',
        'from',
        'to',
        'subject',
        'received_at',
        'raw_payload',
        'body_text',
        'status',
        'process_id',
        'ai_classification',
        'error',
        'processed_at',
        'respondido_at',
    ];

    protected $casts = [
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
        'respondido_at' => 'datetime',
        'raw_payload' => 'array',
        'ai_classification' => 'array',
    ];

    public function process(): BelongsTo
    {
        return $this->belongsTo(Process::class);
    }

    public function integrationToken(): BelongsTo
    {
        return $this->belongsTo(IntegrationToken::class);
    }

    /**
     * Cada abogada ve su bandeja; el director las ve todas.
     *
     * Mientras hubo una sola cuenta compartida esto no hacía falta, y todo el
     * mundo veía el correo de todos los clientes. Con cuentas personales eso
     * deja de ser aceptable: el correo de una abogada es suyo.
     *
     * Los correos sin cuenta atribuida son los que entraron por la bandeja de
     * automatización antes de este cambio. No tienen dueño a quien devolvérse-
     * los, así que los ve solo el director: es el único reparto que no se los
     * enseña a alguien que no debería verlos.
     */
    public function scopeVisiblePara(Builder $query, User $usuario): Builder
    {
        if ($usuario->hasRole('director')) {
            return $query;
        }

        return $query->whereHas(
            'integrationToken',
            fn (Builder $q) => $q->where('connected_by_user_id', $usuario->id),
        );
    }

    /**
     * Si este correo es de la bandeja de quien pregunta.
     *
     * El listado ya filtra, pero filtrar la lista no protege nada: la accion
     * llega por id y un id se adivina. Esta es la comprobacion que cuenta.
     */
    public function loPuedeVer(User $usuario): bool
    {
        if ($usuario->hasRole('director')) {
            return true;
        }

        return $this->integration_token_id !== null
            && $this->integrationToken?->connected_by_user_id === $usuario->id;
    }
}
