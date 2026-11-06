<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Access;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        Access::admin($request->user());
        $users = User::where('username', '!=', config('tourism.admin_username'))->latest()->get()->map(fn ($u) => $u->sessionData(true));

        return response()->json(['count' => $users->count(), 'results' => $users]);
    }

    private function target(Request $request, string $username): User
    {
        Access::admin($request->user());
        $user = User::where('username', $username)->firstOrFail();
        abort_if($user->role === 'admin', 403, 'Không thể sửa tài khoản quản trị hệ thống.');

        return $user;
    }

    public function update(Request $request, string $username): JsonResponse
    {
        $user = $this->target($request, $username);
        AuthController::normalize($request);
        $data = $request->validate(AuthController::identityRules($user) + ['role' => 'required|in:manager,user', 'permissions' => 'sometimes|array', 'permissions.categories' => 'sometimes|array', 'permissions.groups' => 'sometimes|array', 'permissions.subgroups' => 'sometimes|array', 'permissions.categories.*' => 'string', 'permissions.groups.*' => 'string', 'permissions.subgroups.*' => 'string']);
        AuthController::checkUsername($data['username'], $user->id);
        $data['permissions'] = array_merge(['categories' => [], 'groups' => [], 'subgroups' => []], array_intersect_key($data['permissions'] ?? [], array_flip(['categories', 'groups', 'subgroups'])));
        DB::transaction(fn () => $user->update($this->snake($data)));

        return response()->json($user->sessionData(true));
    }

    public function destroy(Request $request, string $username): JsonResponse
    {
        $this->target($request, $username)->delete();

        return $this->success();
    }

    public function resetPassword(Request $request, string $username): JsonResponse
    {
        $user = $this->target($request, $username);
        $password = config('tourism.reset_password') ?: bin2hex(random_bytes(8));
        $user->update(['password' => $password, 'must_change_password' => true, 'password_reset_at' => now()]);

        return response()->json(['success' => true, 'newPassword' => $password]);
    }
}
