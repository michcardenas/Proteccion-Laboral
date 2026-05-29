<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Comment extends Model
{
    use HasFactory;

    protected $fillable = [
        'commentable_id',
        'commentable_type',
        'user_id',
        'email_ingestion_id',
        'body',
        'visible_cliente',
    ];

    protected $casts = [
        'visible_cliente' => 'boolean',
    ];

    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function emailIngestion(): BelongsTo
    {
        return $this->belongsTo(EmailIngestion::class);
    }
}
