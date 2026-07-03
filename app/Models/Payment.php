<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payment extends Model
{
    use SoftDeletes;

    public const METODOS = ['efectivo', 'transferencia', 'consignacion', 'tarjeta', 'cheque', 'otro'];

    protected $fillable = [
        'process_id',
        'client_id',
        'registrado_por',
        'monto',
        'fecha_pago',
        'concepto',
        'metodo',
        'referencia',
        'notas',
    ];

    protected $casts = [
        'monto' => 'decimal:2',
        'fecha_pago' => 'date',
    ];

    public function process(): BelongsTo
    {
        return $this->belongsTo(Process::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }
}
