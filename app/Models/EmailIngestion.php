<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailIngestion extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_CLASSIFIED = 'classified';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_NEEDS_REVIEW = 'needs_review';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'message_id',
        'from',
        'to',
        'subject',
        'received_at',
        'raw_payload',
        'body_text',
        'status',
        'process_id',
        'ai_classification',
        'error',
        'processed_at',
    ];

    protected $casts = [
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
        'raw_payload' => 'array',
        'ai_classification' => 'array',
    ];

    public function process(): BelongsTo
    {
        return $this->belongsTo(Process::class);
    }
}
