<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;

class FileValidationService
{
    public const LABEL_MAX = 255;

    /**
     * @return array{mime: string, size: int, category: string, max_size_mb: int}|null
     */
    public function inspect(UploadedFile $file): ?array
    {
        if (! $file || ! $file->isValid()) {
            return null;
        }

        $mime = $this->detectMime($file);
        $size = (int) $file->getSize();
        $category = $this->categoryFor($mime);

        if ($category === null) {
            return [
                'mime' => $mime,
                'size' => $size,
                'category' => null,
                'max_size_mb' => 0,
            ];
        }

        return [
            'mime' => $mime,
            'size' => $size,
            'category' => $category['name'],
            'max_size_mb' => (int) $category['max_size_mb'],
        ];
    }

    public function detectMime(UploadedFile $file): string
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

    public function categoryFor(string $mime): ?array
    {
        $categories = (array) config('files.categories', []);

        foreach ($categories as $name => $category) {
            $allowed = (array) ($category['mimes'] ?? []);
            if (in_array($mime, $allowed, true)) {
                return [
                    'name' => (string) $name,
                    'mimes' => $allowed,
                    'max_size_mb' => (int) ($category['max_size_mb'] ?? 0),
                ];
            }
        }

        return null;
    }

    public function maxSizeBytesFor(string $mime): ?int
    {
        $category = $this->categoryFor($mime);
        if ($category === null) {
            return null;
        }

        return $category['max_size_mb'] * 1024 * 1024;
    }
}
