<?php

namespace App\Services;

use App\Exceptions\AvatarTooLargeException;
use App\Exceptions\InvalidAvatarMimeException;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Throwable;

class AvatarService
{
    public const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public const MAX_SIZE_BYTES = 2 * 1024 * 1024;

    public const RESIZE_TO = 400;

    public const MIME_TO_EXT = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function store(User $user, UploadedFile $file): string
    {
        $mime = $this->detectMime($file);

        if (! in_array($mime, self::ALLOWED_MIMES, true)) {
            throw new InvalidAvatarMimeException($mime);
        }

        $size = (int) $file->getSize();
        if ($size > self::MAX_SIZE_BYTES) {
            throw new AvatarTooLargeException((string) $size);
        }

        $ext = self::MIME_TO_EXT[$mime];
        $relPath = 'avatars/' . $user->id . '.' . $ext;

        $this->delete($user);

        $source = $this->loadGdImage($file, $mime);
        if ($source === null) {
            throw new InvalidAvatarMimeException($mime);
        }

        $srcW = imagesx($source);
        $srcH = imagesy($source);
        $side = min($srcW, $srcH);
        $srcX = (int) (($srcW - $side) / 2);
        $srcY = (int) (($srcH - $side) / 2);

        $canvas = imagecreatetruecolor(self::RESIZE_TO, self::RESIZE_TO);
        if ($canvas === false) {
            imagedestroy($source);
            throw new InvalidAvatarMimeException($mime);
        }

        if ($mime === 'image/png' || $mime === 'image/webp') {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
            imagefilledrectangle($canvas, 0, 0, self::RESIZE_TO, self::RESIZE_TO, $transparent);
        }

        imagecopyresampled(
            $canvas,
            $source,
            0,
            0,
            $srcX,
            $srcY,
            self::RESIZE_TO,
            self::RESIZE_TO,
            $side,
            $side
        );

        $tmp = tempnam(sys_get_temp_dir(), 'avatar_');
        if ($tmp === false) {
            imagedestroy($source);
            imagedestroy($canvas);
            throw new InvalidAvatarMimeException($mime);
        }

        try {
            $ok = match ($mime) {
                'image/jpeg' => imagejpeg($canvas, $tmp, 90),
                'image/png' => imagepng($canvas, $tmp, 6),
                'image/webp' => imagewebp($canvas, $tmp, 90),
            };

            if (! $ok) {
                throw new InvalidAvatarMimeException($mime);
            }

            $disk = Storage::disk('local');
            $diskRoot = $disk->path('');

            if (! is_dir($diskRoot . '/avatars')) {
                @mkdir($diskRoot . '/avatars', 0775, true);
            }

            $contents = file_get_contents($tmp);
            if ($contents === false) {
                throw new InvalidAvatarMimeException($mime);
            }

            $disk->put($relPath, $contents);

            $user->avatar_path = $relPath;
            $user->save();
        } finally {
            @unlink($tmp);
            imagedestroy($source);
            imagedestroy($canvas);
        }

        return $relPath;
    }

    public function delete(User $user): void
    {
        if ($user->avatar_path === null || $user->avatar_path === '') {
            return;
        }

        $stored = (string) $user->avatar_path;
        if (str_contains($stored, '..') || str_contains($stored, '/' . '/')) {
            return;
        }

        $disk = Storage::disk('local');
        $diskRoot = realpath($disk->path(''));
        if ($diskRoot === false) {
            return;
        }

        $abs = realpath($disk->path($stored));
        if ($abs === false) {
            $disk->delete($stored);

            return;
        }

        if (! str_starts_with($abs, $diskRoot . DIRECTORY_SEPARATOR)) {
            return;
        }

        $disk->delete($stored);
    }

    private function detectMime(UploadedFile $file): string
    {
        $path = $file->getRealPath();
        if (is_string($path) && is_file($path) && is_readable($path)) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $detected = $finfo->file($path);
            if (is_string($detected) && $detected !== '') {
                return $detected;
            }
        }

        return (string) $file->getMimeType();
    }

    private function loadGdImage(UploadedFile $file, string $mime)
    {
        $path = $file->getRealPath();
        if (! is_string($path) || ! is_readable($path)) {
            return null;
        }

        try {
            return match ($mime) {
                'image/jpeg' => @imagecreatefromjpeg($path),
                'image/png' => @imagecreatefrompng($path),
                'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : null,
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
    }
}
