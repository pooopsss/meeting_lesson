<?php

namespace App\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Model implements AuthenticatableContract
{
    use Authenticatable;

    protected $fillable = [
        'email',
        'password',
        'phone',
        'avatar_path',
    ];

    protected $hidden = [
        'password',
    ];

    protected $appends = [
        'avatar_url',
        'initials',
        'color',
    ];

    public function sessions(): HasMany
    {
        return $this->hasMany(UserSession::class);
    }

    public function uploadedMeetingFiles(): HasMany
    {
        return $this->hasMany(MeetingFile::class);
    }

    /**
     * URL аватарки. Если файл задан — отдаём маршрут /api/me/avatar,
     * иначе null (фронт покажет инициалы на цветном фоне).
     */
    protected function avatarUrl(): Attribute
    {
        return Attribute::get(function (): ?string {
            return $this->avatar_path !== null && $this->avatar_path !== ''
                ? '/api/me/avatar'
                : null;
        });
    }

    /**
     * Инициалы из первых двух слов name, в верхнем регистре.
     * Если name пустое или отсутствует — пустая строка.
     */
    protected function initials(): Attribute
    {
        return Attribute::get(function (): string {
            $name = trim((string) $this->getAttribute('name'));
            if ($name === '') {
                return '';
            }

            $letters = '';
            foreach (array_slice(preg_split('/\s+/u', $name) ?: [], 0, 2) as $word) {
                $letters .= mb_strtoupper(mb_substr($word, 0, 1));
            }

            return $letters;
        });
    }

    /**
     * Детерминированный hex-цвет фона на основе user_id.
     * Стабильный между перезагрузками и сессиями.
     * Для несохранённого пользователя (id is null) — фолбэк по email,
     * чтобы каждый черновик получал уникальный цвет вместо коллапса в hue=0.
     */
    protected function color(): Attribute
    {
        return Attribute::get(function (): string {
            $hue = $this->id !== null
                ? ((int) $this->id * 137) % 360
                : (crc32((string) $this->getAttribute('email')) % 360);
            [$r, $g, $b] = $this->hslToRgb($hue / 360, 0.65, 0.5);

            return sprintf('#%02X%02X%02X', $r, $g, $b);
        });
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    private function hslToRgb(float $h, float $s, float $l): array
    {
        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
        $p = 2 * $l - $q;

        return [
            (int) round($this->hueToRgb($p, $q, $h + 1 / 3) * 255),
            (int) round($this->hueToRgb($p, $q, $h) * 255),
            (int) round($this->hueToRgb($p, $q, $h - 1 / 3) * 255),
        ];
    }

    private function hueToRgb(float $p, float $q, float $t): float
    {
        if ($t < 0) {
            $t += 1;
        }
        if ($t > 1) {
            $t -= 1;
        }
        if ($t < 1 / 6) {
            return $p + ($q - $p) * 6 * $t;
        }
        if ($t < 1 / 2) {
            return $q;
        }
        if ($t < 2 / 3) {
            return $p + ($q - $p) * (2 / 3 - $t) * 6;
        }
        return $p;
    }
}
