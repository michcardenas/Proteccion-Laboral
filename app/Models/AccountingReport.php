<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'periodo',
        'tipo',
        'archivo',
        'subido_por',
        'notas',
    ];

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subido_por');
    }
}
