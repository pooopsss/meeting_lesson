<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChangePasswordRequest;
use App\Services\AvatarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class UserController extends Controller
{
    private AvatarService $avatars;

    public function __construct()
    {
        $this->avatars = app(AvatarService::class);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($request->user(), 200);
    }

    public function update(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'phone' => [
                'nullable',
                'string',
                'max:20',
                'regex:/^[0-9+\s()\-]+$/',
            ],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        $phone = $request->input('phone');
        $user->name = $request->input('name');
        $user->phone = ($phone === null || $phone === '') ? null : $phone;
        $user->save();

        Log::info('user: profile updated', [
            'user_id' => $user->id,
            'status' => 'ok',
        ]);

        return response()->json($user->fresh(), 200);
    }

    public function uploadAvatar(Request $request): JsonResponse
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'avatar' => 'required|file',
        ], [], [
            'avatar' => 'аватарка',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $file = $request->file('avatar');
        if (! $file || ! $file->isValid()) {
            Log::warning('user_avatar: upload invalid', [
                'user_id' => $user->id,
                'status' => 'error',
            ]);

            return response()->json(['errors' => ['avatar' => ['Не удалось загрузить файл.']]], 422);
        }

        try {
            $avatar = $this->avatars->store($user, $file);
        } catch (\App\Exceptions\InvalidAvatarMimeException $e) {
            Log::warning('user_avatar: invalid mime', [
                'user_id' => $user->id,
                'mime' => $e->getMessage(),
                'status' => 'error',
            ]);

            return response()->json([
                'errors' => ['avatar' => ['Недопустимый формат изображения. Разрешены JPG, PNG, WebP.']],
            ], 422);
        } catch (\App\Exceptions\AvatarTooLargeException $e) {
            Log::warning('user_avatar: too large', [
                'user_id' => $user->id,
                'size' => $e->getMessage(),
                'status' => 'error',
            ]);

            return response()->json([
                'errors' => ['avatar' => ['Файл слишком большой. Максимальный размер — 2 МБ.']],
            ], 422);
        } catch (\Throwable $e) {
            Log::error('user_avatar: storage failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'status' => 'error',
            ]);

            return response()->json(['message' => 'Не удалось сохранить аватарку'], 500);
        }

        Log::info('user_avatar: uploaded', [
            'user_id' => $user->id,
            'avatar_path' => $avatar,
            'status' => 'ok',
        ]);

        return response()->json($user->fresh(), 200);
    }

    public function deleteAvatar(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->avatar_path !== null && $user->avatar_path !== '') {
            $this->avatars->delete($user);
            $user->avatar_path = null;
            $user->save();

            Log::info('user_avatar: deleted', [
                'user_id' => $user->id,
                'status' => 'ok',
            ]);
        }

        return response()->json($user->fresh(), 200);
    }

    public function showAvatar(Request $request)
    {
        $user = $request->user();

        if ($user->avatar_path === null || $user->avatar_path === '') {
            return response()->json(['message' => 'Аватарка не найдена'], 404);
        }

        $stored = (string) $user->avatar_path;
        if (str_contains($stored, '..')) {
            return response()->json(['message' => 'Аватарка не найдена'], 404);
        }

        $disk = Storage::disk('local');
        $diskRoot = realpath($disk->path(''));
        $abs = realpath($disk->path($stored));

        if ($diskRoot === false
            || $abs === false
            || ! str_starts_with($abs, $diskRoot . DIRECTORY_SEPARATOR)
            || ! is_file($abs)) {
            return response()->json(['message' => 'Аватарка не найдена'], 404);
        }

        $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };

        return response()->file($abs, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="avatar.' . $ext . '"',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $form = ChangePasswordRequest::make($request);
        $result = $form->validate();

        if (isset($result['errors'])) {
            return response()->json(['errors' => $result['errors']], 422);
        }

        $user = $request->user();

        if (! Hash::check($result['current_password'], $user->password)) {
            Log::warning('user_password: wrong current', [
                'user_id' => $user->id,
                'status' => 'error',
            ]);

            return response()->json([
                'errors' => ['current_password' => ['Неверный текущий пароль']],
            ], 422);
        }

        $user->password = Hash::make($result['new_password']);
        $user->save();

        Log::info('user_password: changed', [
            'user_id' => $user->id,
            'status' => 'ok',
        ]);

        return response()->json(['message' => 'Пароль изменён'], 200);
    }
}
