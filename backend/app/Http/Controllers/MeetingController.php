<?php

namespace App\Http\Controllers;

use App\Models\Meeting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MeetingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $meetings = Meeting::where('user_id', $request->user()->id)
            ->orderBy('id')
            ->get();

        return response()->json($meetings, 200);
    }

    public function show(Request $request, $id): JsonResponse
    {
        $meeting = Meeting::where('user_id', $request->user()->id)
            ->where('id', $id)
            ->first();

        if (! $meeting) {
            return response()->json(['message' => 'Встреча не найдена'], 404);
        }

        return response()->json($meeting, 200);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'scheduled_at' => 'required|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $meeting = Meeting::create([
            'user_id' => $request->user()->id,
            'title' => $request->input('title'),
            'description' => $request->input('description'),
            'scheduled_at' => $request->input('scheduled_at'),
        ]);

        return response()->json($meeting, 201);
    }
}
