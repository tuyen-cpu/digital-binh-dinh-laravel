<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewReply extends ApiModel
{
    use HasUuids;

    public const UPDATED_AT = null;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }

    public function toArray(): array
    {
        return ['id' => $this->id, 'username' => $this->user->username, 'displayName' => $this->user->display_name,
            'role' => $this->user->role, 'avatar' => $this->user->avatar, 'comment' => $this->comment, 'createdAt' => $this->created_at->toISOString()];
    }
}
