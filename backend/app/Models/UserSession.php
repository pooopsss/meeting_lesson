<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserSession extends Model
{
    protected $fillable = [
        'user_id',
        'token',
    ];

    protected $hidden = [
        'token',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function issueToken(User $user): string
    {
        $token = Str::random(64);

        static::create([
            'user_id' => $user->id,
            'token' => Hash::make($token),
        ]);

        return $token;
    }
}
