<?php

namespace App\Http\Requests;

use App\Services\FileValidationService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator as ValidatorFactory;
use Illuminate\Support\MessageBag;

class StoreMeetingFileRequest
{
    public const LABEL_MAX = FileValidationService::LABEL_MAX;

    private Request $request;

    private FileValidationService $files;

    public function __construct(Request $request, FileValidationService $files)
    {
        $this->request = $request;
        $this->files = $files;
    }

    public static function make(Request $request, FileValidationService $files): self
    {
        return new self($request, $files);
    }

    /**
     * @return array{file: UploadedFile, label: string|null}|array{errors: MessageBag}
     */
    public function validate(): array
    {
        $validator = ValidatorFactory::make($this->request->all(), [
            'file' => 'required|file',
            'label' => 'nullable|string|max:' . self::LABEL_MAX,
        ]);

        if ($validator->fails()) {
            return ['errors' => $validator->errors()];
        }

        $uploaded = $this->request->file('file');
        $label = $this->request->input('label');

        $contentErrors = $this->validateFile($uploaded);
        $labelErrors = $this->validateLabel($label);

        if (! empty($contentErrors) || ! empty($labelErrors)) {
            return ['errors' => $this->makeMessageBag(array_merge($contentErrors, $labelErrors))];
        }

        return [
            'file' => $uploaded,
            'label' => $label,
        ];
    }

    private function validateFile(?UploadedFile $file): array
    {
        if (! $file || ! $file->isValid()) {
            return ['file' => ['File upload failed.']];
        }

        $inspection = $this->files->inspect($file);
        if ($inspection === null) {
            return ['file' => ['File upload failed.']];
        }

        if ($inspection['category'] === null) {
            return ['file' => ['Disallowed file type.']];
        }

        $maxBytes = $inspection['max_size_mb'] * 1024 * 1024;
        if ($inspection['size'] > $maxBytes) {
            return ['file' => [sprintf(
                'File too large. Max size for %s is %d MB.',
                $inspection['category'],
                $inspection['max_size_mb']
            )]];
        }

        return [];
    }

    private function validateLabel(mixed $label): array
    {
        if ($label === null || $label === '') {
            return [];
        }
        if (! is_string($label)) {
            return ['label' => ['Label must be a string.']];
        }
        if (mb_strlen($label) > self::LABEL_MAX) {
            return ['label' => [sprintf('Label must be at most %d characters.', self::LABEL_MAX)]];
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $label)) {
            return ['label' => ['Label contains control characters.']];
        }

        return [];
    }

    private function makeMessageBag(array $errors): MessageBag
    {
        $bag = new MessageBag();
        foreach ($errors as $key => $messages) {
            foreach ((array) $messages as $message) {
                $bag->add($key, $message);
            }
        }

        return $bag;
    }
}
