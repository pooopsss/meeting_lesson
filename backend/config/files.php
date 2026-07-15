<?php

return [
    'categories' => [
        'document' => [
            'mimes' => [
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ],
            'max_size_mb' => 20,
        ],
        'image' => [
            'mimes' => [
                'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml',
            ],
            'max_size_mb' => 20,
        ],
        'text' => [
            'mimes' => [
                'text/plain', 'text/csv', 'text/markdown',
            ],
            'max_size_mb' => 20,
        ],
        'archive' => [
            'mimes' => [
                'application/zip',
            ],
            'max_size_mb' => 20,
        ],
        'audio' => [
            'mimes' => [
                'audio/mpeg', 'audio/mp4', 'audio/wav', 'audio/ogg',
                'audio/webm', 'audio/aac', 'audio/x-aac', 'audio/flac', 'audio/x-m4a',
            ],
            'max_size_mb' => 200,
        ],
        'video' => [
            'mimes' => [
                'video/mp4', 'video/webm', 'video/ogg',
                'video/quicktime', 'video/x-matroska', 'video/x-msvideo',
            ],
            'max_size_mb' => 200,
        ],
    ],
];
