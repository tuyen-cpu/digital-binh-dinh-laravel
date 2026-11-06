<?php

namespace App\Http\Controllers;

use App\Models\TokenSession;
use App\Models\User;
use App\Services\MediaLifecycle;
use App\Services\Tokens;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends ApiController
{
    public static function identityRules(?User $user = null): array
    {
        return ['username' => ['required', 'string', 'regex:/^[a-zA-Z0-9._-]{3,40}$/', Rule::unique('users')->ignore($user?->id)],
            'displayName' => 'required|string|max:150', 'phone' => ['required', 'string', 'regex:/^(\+84|0)(3|5|7|8|9)\d{8}$/', Rule::unique('users')->ignore($user?->id)]];
    }

    public static function normalize(Request $request): void
    {
        if ($request->has('phone') && is_string($request->input('phone'))) {
            $request->merge(['phone' => str_replace([' ', '-', '.'], '', $request->input('phone'))]);
        }
    }

    public static function checkUsername(string $name, ?int $ignore = null): void
    {
        if (strcasecmp($name, config('tourism.admin_username')) === 0 || User::whereRaw('LOWER(username) = ?', [strtolower($name)])->when($ignore, fn ($q) => $q->where('id', '!=', $ignore))->exists()) {
            throw ValidationException::withMessages(['username' => ['Tên đăng nhập đã tồn tại hoặc dành cho quản trị viên.']]);
        }
    }

    public function register(Request $request, Tokens $tokens): JsonResponse
    {
        self::normalize($request);
        $data = $request->validate(self::identityRules() + ['password' => 'required|string|min:6']);
        self::checkUsername($data['username']);

        return DB::transaction(function () use ($data, $tokens) {
            $user = User::create($this->snake($data));

            return response()->json($tokens->issue($user) + ['user' => $user->fresh()->sessionData()], 201);
        });
    }

    public function login(Request $request, Tokens $tokens): JsonResponse
    {
        $d = $request->validate(['username' => 'required|string', 'password' => 'required|string']);
        $adminPassword = config('tourism.admin_password');
        if ($adminPassword !== '' && strcasecmp($d['username'], config('tourism.admin_username')) === 0 && hash_equals($adminPassword, $d['password'])) {
            $user = User::firstOrCreate(['username' => config('tourism.admin_username')], ['password' => Hash::make(bin2hex(random_bytes(32))), 'display_name' => config('tourism.admin_display_name'), 'role' => 'admin']);
        } else {
            $user = User::whereRaw('LOWER(username) = ?', [strtolower($d['username'])])->first();
            abort_unless($user && Hash::check($d['password'], $user->password), 401, 'Tên đăng nhập hoặc mật khẩu không đúng.');
        }

        return response()->json($tokens->issue($user) + ['user' => $user->sessionData()]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($request->user()->sessionData());
    }

    public function logout(Request $request): JsonResponse
    {
        TokenSession::whereKey($request->attributes->get('token_session'))->update(['revoked_at' => now()]);

        return $this->success();
    }

    public function profile(Request $request, MediaLifecycle $media): JsonResponse
    {
        abort_if($request->user()->role === 'admin', 403, 'Không thể cập nhật profile cho tài khoản admin.');
        self::normalize($request);
        $rules = self::identityRules($request->user());
        unset($rules['username']);
        $data = $request->validate($rules + ['avatar' => ['sometimes', 'nullable', 'string', $media->notBlob()]]);

        return DB::transaction(function () use ($request, $data, $media) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $old = $user->avatar;
            $media->attach($data['avatar'] ?? null);
            $user->update($this->snake($data));
            $media->replace([$old], [$user->avatar]);

            return response()->json($user->sessionData());
        });
    }

    public function changePassword(Request $request): JsonResponse
    {
        abort_if($request->user()->role === 'admin', 403, 'Không thể đổi mật khẩu cho tài khoản admin.');
        $data = $request->validate(['currentPassword' => 'required|string', 'newPassword' => 'required|string|min:8']);
        abort_unless(Hash::check($data['currentPassword'], $request->user()->password), 400, 'Mật khẩu hiện tại không đúng.');
        $request->user()->update(['password' => $data['newPassword'], 'must_change_password' => false, 'password_changed_at' => now()]);

        return $this->success();
    }
}
