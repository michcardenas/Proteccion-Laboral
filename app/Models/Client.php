<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Client extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    protected $fillable = [
        'razon_social',
        'nit',
        'dv',
        'ciudad',
        'sector',
        'contacto_principal',
        'email',
        'telefono',
        'fecha_alta',
        'estado',
        'notas',
    ];

    protected $casts = [
        'fecha_alta' => 'date',
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
