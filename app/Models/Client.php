<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

// El cliente se autentica en el portal con su NIT + contraseña (guard `client`),
// por eso extiende Authenticatable en vez de Model.
class Client extends Authenticatable
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'razon_social',
        'nit',
        'dv',
        'ciudad',
        'sector',
        'contacto_principal',
        'email',
        'telefono',
        'password',
        'portal_activo',
        'portal_last_login_at',
        'fecha_alta',
        'estado',
        'notas',
        'resumen_documental',
        'resumen_documental_at',
        'drive_folder_id',
        'drive_folder_name',
        'drive_synced_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'fecha_alta' => 'date',
        'portal_activo' => 'boolean',
        'portal_last_login_at' => 'datetime',
        'password' => 'hashed',
        'resumen_documental_at' => 'datetime',
        'drive_synced_at' => 'datetime',
    ];

    public function contactos(): HasMany
    {
        return $this->hasMany(ClientContact::class);
    }

    public function contactoPrincipal(): HasMany
    {
        return $this->hasMany(ClientContact::class)->where('es_principal', true);
    }

    public function asignados(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'client_user')
            ->withPivot('rol_asignacion')
            ->withTimestamps();
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    public function processes(): HasMany
    {
        return $this->hasMany(Process::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /**
     * TODOS los documentos del cliente, estén o no atados a un proceso.
     *
     * Filtraba por `process_id IS NULL` porque cuando se escribió la ficha era
     * lo único que existía y no había contexto por proceso. Al empezar a
     * espejar Drive eso se volvió una trampa: la estructura real del despacho
     * es `cliente / asunto / archivos` —ELIAS ACOSTA tiene 52 carpetas—, así
     * que en cuanto esos documentos se aten a su proceso, con el filtro la
     * ficha del cliente se habría quedado VACÍA sin que nadie lo notara.
     *
     * La ficha da la panorámica del cliente y `ProcessContextBuilder` da el
     * detalle del asunto concreto. Son dos vistas del mismo material, no dos
     * conjuntos distintos.
     */
    public function documentosCliente(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /**
     * La ficha de conocimiento está desactualizada si nunca se generó teniendo
     * documentos, o si algún documento del cliente cambió después de generarla.
     */
    public function fichaDesactualizada(): bool
    {
        $ultimoCambio = $this->documentosCliente()->max('updated_at');

        if ($ultimoCambio === null) {
            // Sin documentos: no hay nada que resumir, la ficha no "está desactualizada".
            return false;
        }

        if ($this->resumen_documental_at === null) {
            return true;
        }

        return $this->resumen_documental_at->lt($ultimoCambio);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }

    /**
     * El cliente puede acceder al portal si está activo y tiene al menos un
     * proceso con un abogado asignado (líder, apoderado o coordinador).
     */
    public function puedeAccederPortal(): bool
    {
        if (! $this->portal_activo) {
            return false;
        }

        return $this->processes()
            ->where(function ($q) {
                $q->whereNotNull('abogado_lider_id')
                    ->orWhereNotNull('apoderado_id')
                    ->orWhereNotNull('coordinador_id');
            })
            ->exists();
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['razon_social', 'nit', 'estado', 'contacto_principal', 'email'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
