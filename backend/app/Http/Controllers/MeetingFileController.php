<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMeetingFileRequest;
use App\Models\Meeting;
use App\Models\MeetingFile;
use App\Services\FileValidationService;
use App\Services\FileValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class MeetingFileController extends Controller
{
    private FileValidator $files;

    private FileValidationService $fileValidation;

    public function __construct()
    {
        $this->files = app(FileValidator::class);
        $this->fileValidation = app(FileValidationService::class);
    }

    public function store(Request $request, $id): JsonResponse
    {
        $meeting = $this->findOwnedMeeting($request, $id);
        if (! $meeting) {
            return response()->json(['message' => 'Meeting not found'], 404);
        }

        $form = StoreMeetingFileRequest::make($request, $this->fileValidation);
        $result = $form->validate();

        if (isset($result['errors'])) {
            Log::warning('meeting_file: validation failed', [
                'meeting_id' => $meeting->id,
                'user_id' => $request->user()->id,
                'errors' => $result['errors']->toArray(),
                'status' => 'error',
            ]);

            return response()->json(['errors' => $result['errors']], 422);
        }

        $uploaded = $result['file'];
        $label = $result['label'];

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
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
                'status' => 'error',
            ]);

            return response()->json(['message' => 'Failed to store file'], 500);
        }

        try {
            $row = MeetingFile::create([
                'meeting_id' => $meeting->id,
                'user_id' => $request->user()->id,
                'original_name' => $originalName,
                'stored_name' => $storedName,
                'mime_type' => $this->fileValidation->detectMime($uploaded),
                'size' => $uploaded->getSize(),
                'label' => $label,
            ]);
        } catch (Throwable $e) {
            Storage::disk('local')->delete($storedRel);
            Log::error('meeting_file: db write failed, file rolled back', [
                'meeting_id' => $meeting->id,
                'user_id' => $request->user()->id,
                'path' => $storedRel,
                'error' => $e->getMessage(),
                'status' => 'error',
            ]);

            return response()->json(['message' => 'Failed to record file'], 500);
        }

        Log::info('meeting_file: uploaded', [
            'meeting_id' => $meeting->id,
            'user_id' => $request->user()->id,
            'file_id' => $row->id,
            'size' => $row->size,
            'mime_type' => $row->mime_type,
            'status' => 'ok',
        ]);

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
            Log::warning('meeting_file: download failed (file missing on disk)', [
                'meeting_id' => $meeting->id,
                'user_id' => $request->user()->id,
                'file_id' => $file->id,
                'stored_name' => $file->stored_name,
                'status' => 'error',
            ]);

            return response()->json(['message' => 'File not found'], 404);
        }

        $downloadName = $this->files->sanitizeOriginalName($file->original_name);

        Log::info('meeting_file: downloaded', [
            'meeting_id' => $meeting->id,
            'user_id' => $request->user()->id,
            'file_id' => $file->id,
            'size' => $file->size,
            'status' => 'ok',
        ]);

        return response()->download($abs, $downloadName ?: 'file-' . $file->id);
    }

    public function index(Request $request, $id)
    {
        $meeting = $this->findOwnedMeeting($request, $id);
        if (! $meeting) {
            return response()->json(['message' => 'Meeting not found'], 404);
        }

        $files = MeetingFile::where('meeting_id', $meeting->id)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        return response()->json($files);
    }

    public function destroy(Request $request, $id, $fileId)
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

        if ((int) $file->user_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $disk = Storage::disk('local');
        $relPath = 'meetings/' . $meeting->id . '/' . $file->stored_name;

        if (str_contains($file->stored_name, '..') || str_contains($file->stored_name, '/')) {
            return response()->json(['message' => 'File not found'], 404);
        }

        $file->delete();

        if ($disk->exists($relPath)) {
            $disk->delete($relPath);
        }

        Log::info('meeting_file: deleted', [
            'meeting_id' => $meeting->id,
            'user_id' => $request->user()->id,
            'file_id' => $file->id,
            'status' => 'ok',
        ]);

        return response()->json(null, 204);
    }

    private function findOwnedMeeting(Request $request, $id): ?Meeting
    {
        return Meeting::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();
    }
}
