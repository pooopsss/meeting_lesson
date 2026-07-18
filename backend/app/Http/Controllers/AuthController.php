<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:6|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = User::create([
            'email' => $request->input('email'),
            'password' => Hash::make($request->input('password')),
        ]);

        $token = UserSession::issueToken($user);

        return response()->json([
            'token' => $token,
            'user' => $user,
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = User::where('email', $request->input('email'))->first();

        if (! $user || ! Hash::check($request->input('password'), $user->password)) {
            return response()->json(['message' => 'Неверный email или пароль'], 401);
        }

        $token = UserSession::issueToken($user);

        return response()->json([
            'token' => $token,
            'user' => $user,
        ], 200);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = (string) $request->bearerToken();
        $userId = (int) $request->user()->id;

        if ($token !== '') {
            foreach (UserSession::where('user_id', $userId)->get() as $session) {
                if (Hash::check($token, $session->token)) {
                    $session->delete();
                    break;
                }
            }
        }

        return response()->json(null, 204);
    }
}
