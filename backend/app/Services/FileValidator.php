<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class FileValidator
{
    public const LABEL_MAX = 255;

    public function validate(UploadedFile $file, ?string $label): array
    {
        $errors = [];

        if (! $file || ! $file->isValid()) {
            $errors['file'][] = 'File upload failed.';

            return $errors;
        }

        $mime = $file->getMimeType();
        $size = (int) $file->getSize();
        $category = $this->resolveCategory($mime);

        if ($category === null) {
            $errors['file'][] = 'Disallowed file type.';

            return $errors;
        }

        $maxBytes = $category['max_size_mb'] * 1024 * 1024;
        if ($size > $maxBytes) {
            $errors['file'][] = sprintf(
                'File too large. Max size for this type is %d MB.',
                $category['max_size_mb']
            );
        }

        if ($label !== null) {
            if (! $this->isPrintable($label)) {
                $errors['label'][] = 'Label contains control characters.';
            } elseif (Str::length($label) > self::LABEL_MAX) {
                $errors['label'][] = sprintf('Label must be at most %d characters.', self::LABEL_MAX);
            }
        }

        return $errors;
    }

    public function sanitizeOriginalName(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        $name = basename($name);
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = preg_replace('/[\r\n"]/u', '', $name) ?? '';
        $name = str_replace('..', '', $name);
        if (Str::length($name) > 255) {
            $name = Str::limit($name, 255, '');
        }

        return $name;
    }

    public function safeExtension(UploadedFile $file): string
    {
        $ext = strtolower($file->guessExtension() ?: '');
        if ($ext === '' || $this->isUnsafeExtension($ext)) {
            $ext = $this->fallbackExtension($file->getMimeType());
        }
        if ($ext === '' || $this->isUnsafeExtension($ext)) {
            $ext = 'bin';
        }

        return $ext;
    }

    protected function resolveCategory(string $mime): ?array
    {
        $categories = (array) config('files.categories', []);

        foreach ($categories as $category) {
            $allowed = (array) ($category['mimes'] ?? []);
            if (in_array($mime, $allowed, true)) {
                return $category;
            }
        }

        return null;
    }

    protected function isPrintable(string $value): bool
    {
        return ! preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value);
    }

    protected function isUnsafeExtension(string $ext): bool
    {
        static $block = [
            'php', 'phtml', 'phar', 'pht',
            'cgi', 'pl', 'py',
            'sh', 'bash',
            'js', 'jsx', 'mjs',
            'html', 'htm', 'xhtml', 'svg',
            'htaccess', 'htpasswd',
            'exe', 'bat', 'cmd', 'com', 'msi',
            'jar', 'war',
        ];

        return in_array($ext, $block, true);
    }

    protected function fallbackExtension(string $mime): string
    {
        $map = [
            'application/pdf' => 'pdf',
            'application/zip' => 'zip',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'text/plain' => 'txt',
            'text/csv' => 'csv',
            'text/markdown' => 'md',
            'audio/mpeg' => 'mp3',
            'audio/mp4' => 'm4a',
            'audio/wav' => 'wav',
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
        ];

        return $map[$mime] ?? '';
    }
}
