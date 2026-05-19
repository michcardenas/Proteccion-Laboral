<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceChecklistItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'service_type_id',
        'service_stage_template_id',
        'descripcion',
        'es_obligatorio',
        'orden',
    ];

    protected $casts = [
        'es_obligatorio' => 'boolean',
    ];

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }

    public function stageTemplate(): BelongsTo
    {
        return $this->belongsTo(ServiceStageTemplate::class, 'service_stage_template_id');
    }
}
