<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    protected $guarded = [];

    public function sessionData(bool $admin = false): array
    {
        $data = [
            'id' => $this->id, 'username' => $this->username, 'displayName' => $this->display_name,
            'phone' => $this->phone, 'role' => $this->role, 'avatar' => $this->avatar,
            'mustChangePassword' => $this->must_change_password,
            'permissions' => $this->permissions ?? ['categories' => [], 'groups' => [], 'subgroups' => []],
        ];
        if ($admin) {
            $data['createdAt'] = $this->created_at->toISOString();
        }

        return $data;
    }

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'permissions' => 'array',
            'must_change_password' => 'boolean',
            'password_changed_at' => 'datetime',
            'password_reset_at' => 'datetime',
        ];
    }
}
