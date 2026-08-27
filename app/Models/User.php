<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, LogsActivity, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'is_active',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'phone', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(Client::class, 'client_user')
            ->withPivot('rol_asignacion')
            ->withTimestamps();
    }

    public function processesAsLider(): HasMany
    {
        return $this->hasMany(Process::class, 'abogado_lider_id');
    }

    public function processesAsApoderado(): HasMany
    {
        return $this->hasMany(Process::class, 'apoderado_id');
    }

    public function processesAsCoordinador(): HasMany
    {
        return $this->hasMany(Process::class, 'coordinador_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'asignado_a');
    }

    public function aiGenerations(): HasMany
    {
        return $this->hasMany(AiGeneration::class);
    }
}
