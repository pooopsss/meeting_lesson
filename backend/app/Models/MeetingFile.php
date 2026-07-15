<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingFile extends Model
{
    protected $fillable = [
        'meeting_id',
        'user_id',
        'original_name',
        'stored_name',
        'mime_type',
        'size',
        'label',
    ];

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
