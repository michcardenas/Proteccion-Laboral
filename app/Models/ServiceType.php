<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceType extends Model
{
    use HasFactory;

    public const MODALIDADES = [
        'permanente',
        'por_evento',
        'judicial',
        'estrategico',
        'capacitacion',
        'prediagnostico',
        'diagnostico_implementacion',
    ];

    protected $fillable = [
        'nombre',
        'slug',
        'descripcion',
        'modalidad',
        'es_activo',
    ];

    protected $casts = [
        'es_activo' => 'boolean',
    ];

    public function stageTemplates(): HasMany
    {
        return $this->hasMany(ServiceStageTemplate::class)->orderBy('orden');
    }

    /**
     * Plantillas de tarjetas del tablero Kanban para este servicio (rúbrica).
     */
    public function taskTemplates(): HasMany
    {
        return $this->hasMany(ServiceTaskTemplate::class)
            ->where('es_activo', true)
            ->orderBy('orden');
    }

    public function checklistItems(): HasMany
    {
        return $this->hasMany(ServiceChecklistItem::class)
            ->whereNull('service_stage_template_id')
            ->orderBy('orden');
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    public function processes(): HasMany
    {
        return $this->hasMany(Process::class);
    }
}
