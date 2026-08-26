<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationToken extends Model
{
    use HasFactory;

    public const PROVIDER_GMAIL = 'gmail';

    protected $fillable = [
        'provider',
        'account_email',
        'access_token',
        'refresh_token',
        'expires_at',
        'scopes',
        'connected_by_user_id',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'expires_at' => 'datetime',
        'scopes' => 'array',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by_user_id');
    }

    /**
     * ¿El token otorgado incluye este scope? Un scope añadido a la configuración DESPUÉS
     * de conectar la cuenta no está en el token: hay que reconectar para que Google lo
     * emita. Es el caso de `drive.readonly` para la unidad compartida.
     */
    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
