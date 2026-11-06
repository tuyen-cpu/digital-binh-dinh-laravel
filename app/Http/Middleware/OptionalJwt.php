<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Tokens;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class OptionalJwt
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($token = $request->bearerToken()) {
            $payload = app(Tokens::class)->decode($token);
            $user = User::find($payload->user_id);
            abort_unless($user, 401, 'Tài khoản không tồn tại.');
            Auth::setUser($user);
            $request->setUserResolver(fn () => $user);
            $request->attributes->set('token_session', $payload->sid);
        }

        return $next($request);
    }
}
