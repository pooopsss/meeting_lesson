<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Models\UserSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class Authenticate
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $user = null;

        foreach (UserSession::all() as $session) {
            if (Hash::check($token, $session->token)) {
                $user = User::find($session->user_id);
                break;
            }
        }

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
