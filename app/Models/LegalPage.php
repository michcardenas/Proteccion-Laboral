<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una página legal del despacho (privacidad, términos). Ver la migración.
 */
class LegalPage extends Model
{
    /** Marcador de lo que todavía tiene que escribir el despacho. */
    public const MARCADOR = '/\[\[COMPLETAR:[^\]]*\]\]/u';

    protected $fillable = ['slug', 'titulo', 'resumen', 'contenido', 'publicado', 'vigente_desde', 'actualizado_por'];

    protected function casts(): array
    {
        return [
            'publicado' => 'boolean',
            'vigente_desde' => 'date',
        ];
    }

    public function actualizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actualizado_por');
    }

    /** Cuántos huecos quedan por rellenar. */
    public function huecosPendientes(): int
    {
        return preg_match_all(self::MARCADOR, $this->contenido) ?: 0;
    }

    /**
     * Una página con huecos NO se puede publicar.
     *
     * Publicar «[[COMPLETAR: NIT]]» en una política de datos es peor que no
     * tenerla: parece un compromiso adquirido y no dice nada. Google, que es
     * quien va a leer esta URL para verificar la app, tampoco la aceptaría.
     */
    public function puedePublicarse(): bool
    {
        return $this->huecosPendientes() === 0;
    }
}
