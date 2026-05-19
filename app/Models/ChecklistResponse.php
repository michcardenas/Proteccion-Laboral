<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChecklistResponse extends Model
{
    use HasFactory;

    protected $fillable = [
        'process_stage_id',
        'checklist_item_id',
        'descripcion',
        'es_obligatorio',
        'completado',
        'completado_por',
        'completado_at',
        'observacion',
    ];

    protected $casts = [
        'es_obligatorio' => 'boolean',
        'completado' => 'boolean',
        'completado_at' => 'datetime',
    ];

    public function processStage(): BelongsTo
    {
        return $this->belongsTo(ProcessStage::class);
    }

    public function checklistItem(): BelongsTo
    {
        return $this->belongsTo(ServiceChecklistItem::class, 'checklist_item_id');
    }

    public function completador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completado_por');
    }
}
