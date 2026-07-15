<?php

namespace App\Http\Controllers;

use App\Models\Meeting;
use App\Models\MeetingFile;
use App\Services\FileValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class MeetingFileController extends Controller
{
    private FileValidator $files;

    public function __construct()
    {
        $this->files = app(FileValidator::class);
    }

    public function store(Request $request, $id): JsonResponse
    {
        $meeting = $this->findOwnedMeeting($request, $id);
        if (! $meeting) {
            return response()->json(['message' => 'Meeting not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'file' => 'required|file',
            'label' => 'nullable|string|max:' . FileValidator::LABEL_MAX,
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $uploaded = $request->file('file');
        $label = $request->input('label');

        $errors = $this->files->validate($uploaded, $label);
        if (! empty($errors)) {
            return response()->json(['errors' => $errors], 422);
        }

        $ext = $this->files->safeExtension($uploaded);
        $storedName = Str::uuid()->toString() . '.' . $ext;
        $relDir = 'meetings/' . $meeting->id;
        $originalName = $this->files->sanitizeOriginalName($uploaded->getClientOriginalName());

        try {
            $storedRel = $uploaded->storeAs($relDir, $storedName, ['disk' => 'local']);
            if ($storedRel === false) {
                throw new \RuntimeException('storeAs returned false');
            }
        } catch (Throwable $e) {
            Log::error('meeting_file: storage write failed', [
                'meeting_id' => $meeting->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Failed to store file'], 500);
        }

        try {
            $row = MeetingFile::create([
                'meeting_id' => $meeting->id,
                'user_id' => $request->user()->id,
                'original_name' => $originalName,
                'stored_name' => $storedName,
                'mime_type' => $uploaded->getMimeType(),
                'size' => $uploaded->getSize(),
                'label' => $label,
            ]);
        } catch (Throwable $e) {
            Storage::disk('local')->delete($storedRel);
            Log::error('meeting_file: db write failed, file rolled back', [
                'path' => $storedRel,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Failed to record file'], 500);
        }

        return response()->json($row, 201);
    }

    public function download(Request $request, $id, $fileId)
    {
        $meeting = $this->findOwnedMeeting($request, $id);
        if (! $meeting) {
            return response()->json(['message' => 'Meeting not found'], 404);
        }

        $file = MeetingFile::where('id', $fileId)
            ->where('meeting_id', $meeting->id)
            ->first();

        if (! $file) {
            return response()->json(['message' => 'File not found'], 404);
        }

        $disk = Storage::disk('local');
        $diskRoot = realpath($disk->path(''));
        $relPath = 'meetings/' . $meeting->id . '/' . $file->stored_name;

        if ($diskRoot === false || str_contains($file->stored_name, '..') || str_contains($file->stored_name, '/')) {
            return response()->json(['message' => 'File not found'], 404);
        }

        $abs = realpath($disk->path($relPath));

        if ($abs === false
            || ! str_starts_with($abs, $diskRoot . DIRECTORY_SEPARATOR)
            || ! is_file($abs)) {
            return response()->json(['message' => 'File not found'], 404);
        }

        $downloadName = $this->files->sanitizeOriginalName($file->original_name);

        return response()->download($abs, $downloadName ?: 'file-' . $file->id);
    }

    private function findOwnedMeeting(Request $request, $id): ?Meeting
    {
        return Meeting::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();
    }
}
