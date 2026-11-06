<?php

namespace App\Services;

use App\Models\TokenSession;
use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Str;

class Tokens
{
    private function key(): string
    {
        $key = config('app.key');
        if (str_starts_with($key ?? '', 'base64:')) {
            $key = base64_decode(substr($key, 7), true);
        }
        abort_unless(is_string($key) && strlen($key) >= 32, 503, 'Chưa cấu hình APP_KEY.');

        return $key;
    }

    public function issue(User $user): array
    {
        $session = TokenSession::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'expires_at' => now()->addDays(config('tourism.refresh_days'))]);
        $base = ['iss' => config('app.url'), 'iat' => now()->timestamp, 'nbf' => now()->timestamp, 'user_id' => $user->id, 'role' => $user->role, 'username' => $user->username, 'sid' => $session->id];

        return [
            'access' => JWT::encode(array_merge($base, ['token_type' => 'access', 'jti' => (string) Str::uuid(), 'exp' => now()->addMinutes(config('tourism.access_minutes'))->timestamp]), $this->key(), 'HS256'),
            'refresh' => JWT::encode(array_merge($base, ['token_type' => 'refresh', 'jti' => (string) Str::uuid(), 'exp' => $session->expires_at->timestamp]), $this->key(), 'HS256'),
        ];
    }

    public function decode(string $token, string $type = 'access'): object
    {
        try {
            $payload = JWT::decode($token, new Key($this->key(), 'HS256'));
        } catch (\UnexpectedValueException|\DomainException $e) {
            abort(401, 'Token không hợp lệ hoặc đã hết hạn.');
        }
        abort_unless(($payload->iss ?? '') === config('app.url') && ($payload->token_type ?? '') === $type, 401, 'Token không hợp lệ.');
        $session = TokenSession::find($payload->sid ?? '');
        abort_unless($session && ! $session->revoked_at && $session->expires_at->isFuture() && $session->user_id === ($payload->user_id ?? null), 401, 'Phiên đăng nhập đã kết thúc.');

        return $payload;
    }
}
