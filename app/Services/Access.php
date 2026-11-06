<?php

namespace App\Services;

use App\Models\Location;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class Access
{
    public static function admin(?User $user): void
    {
        abort_unless($user?->role === 'admin', 403, 'Chỉ quản trị viên được thực hiện thao tác này.');
    }

    public static function manager(?User $user): void
    {
        abort_unless(in_array($user?->role, ['admin', 'manager']), 403, 'Bạn cần có quyền quản lý.');
    }

    public static function category(User $user, string $category): bool
    {
        return $user->role === 'admin' || ($user->role === 'manager' && in_array($category, $user->permissions['categories'] ?? [], true));
    }

    public static function location(?User $user, Location $location): bool
    {
        if ($user?->role === 'admin') {
            return true;
        }
        if ($user?->role !== 'manager') {
            return false;
        }
        foreach (['category' => 'categories', 'group' => 'groups', 'subgroup' => 'subgroups'] as $field => $scope) {
            if ($location->$field && in_array($location->$field, $user->permissions[$scope] ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    public static function scope(Builder $query, User $user, bool $categoryOnly = false): Builder
    {
        if ($user->role === 'admin') {
            return $query;
        }
        if ($user->role !== 'manager') {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $q) use ($user, $categoryOnly) {
            $q->whereRaw('1 = 0');
            foreach (['category' => 'categories', 'group' => 'groups', 'subgroup' => 'subgroups'] as $field => $scope) {
                if (! $categoryOnly || $field === 'category') {
                    $q->orWhereIn($field, $user->permissions[$scope] ?? []);
                }
            }
        });
    }
}
