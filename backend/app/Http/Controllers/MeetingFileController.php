<?php

namespace App\Http\Controllers;

use App\Models\Meeting;
use App\Models\MeetingFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MeetingFileController extends Controller
{
    public function store(Request $request, $id): JsonResponse
    {
        $meeting = $this->findOwnedMeeting($request, $id);
        if (! $meeting) {
            return response()->json(['message' => 'Meeting not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'file' => 'required|file',
            'label' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $uploaded = $request->file('file');
        $ext = $uploaded->guessExtension() ?: 'bin';
        $storedName = Str::uuid()->toString() . '.' . $ext;
        $relDir = 'meetings/' . $meeting->id;

        $storedRel = $uploaded->storeAs($relDir, $storedName, ['disk' => 'local']);

        $row = MeetingFile::create([
            'meeting_id' => $meeting->id,
            'user_id' => $request->user()->id,
            'original_name' => $uploaded->getClientOriginalName(),
            'stored_name' => $storedName,
            'mime_type' => $uploaded->getMimeType(),
            'size' => $uploaded->getSize(),
            'label' => $request->input('label'),
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

        $relPath = 'meetings/' . $meeting->id . '/' . $file->stored_name;
        $abs = Storage::disk('local')->path($relPath);

        if (! is_file($abs)) {
            return response()->json(['message' => 'File not found'], 404);
        }

        return response()->download($abs, $file->original_name);
    }

    private function findOwnedMeeting(Request $request, $id): ?Meeting
    {
        return Meeting::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();
    }
}
