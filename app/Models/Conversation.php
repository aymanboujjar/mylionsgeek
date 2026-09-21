<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    public const TYPE_DIRECT = 'direct';

    public const TYPE_GROUP = 'group';

    protected $fillable = [
        'type',
        'name',
        'avatar',
        'created_by',
        'user_one_id',
        'user_two_id',
        'last_message_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
    ];

    public function userOne(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_one_id');
    }

    public function userTwo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_two_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('created_at', 'asc');
    }

    public function participantRows(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'conversation_participants')
            ->withPivot(['role', 'joined_at'])
            ->withTimestamps();
    }

    public function isGroup(): bool
    {
        return ($this->type ?? self::TYPE_DIRECT) === self::TYPE_GROUP;
    }

    public function isDirect(): bool
    {
        return ! $this->isGroup();
    }

    /**
     * Whether the user belongs to this conversation (direct pair or group participant).
     */
    public function hasParticipant(int $userId): bool
    {
        if ($this->isGroup()) {
            return $this->participantRows()->where('user_id', $userId)->exists();
        }

        if ((int) $this->user_one_id === $userId || (int) $this->user_two_id === $userId) {
            return true;
        }

        return $this->participantRows()->where('user_id', $userId)->exists();
    }

    /**
     * @return list<int>
     */
    public function participantIds(): array
    {
        if ($this->relationLoaded('participantRows')) {
            return $this->participantRows->pluck('user_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        }

        $ids = $this->participantRows()->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        if ($ids !== []) {
            return array_values(array_unique($ids));
        }

        return array_values(array_filter([
            $this->user_one_id ? (int) $this->user_one_id : null,
            $this->user_two_id ? (int) $this->user_two_id : null,
        ]));
    }

    public function getOtherUser($currentUserId)
    {
        if ($this->isGroup()) {
            return null;
        }

        if ((int) $this->user_one_id === (int) $currentUserId) {
            return $this->userTwo;
        }

        return $this->userOne;
    }

    public function getUnreadCountForUser($userId): int
    {
        return $this->messages()
            ->where('sender_id', '!=', $userId)
            ->where('is_read', false)
            ->count();
    }

    /**
     * Scope: conversations the user can see (direct pair or group membership).
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where(function ($q) use ($userId) {
            $q->where('user_one_id', $userId)
                ->orWhere('user_two_id', $userId)
                ->orWhereHas('participantRows', fn ($p) => $p->where('user_id', $userId));
        });
    }
}
