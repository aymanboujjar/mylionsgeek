<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Organization extends Model
{
    protected $fillable = [
        'email',
        'enterprise_name',
        'contact_name',
        'sector',
        'location',
        'phone',
        'invited_by',
        'account_user_id',
        'onboarding_completed_at',
        'account_state',
    ];

    protected function casts(): array
    {
        return [
            'onboarding_completed_at' => 'datetime',
            'account_state' => 'integer',
        ];
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /** The organisation login account (one per organisation). */
    public function accountUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'account_user_id');
    }

    /** Employer accounts invited by the organisation (not the org account itself). */
    public function employers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_user')
            ->withPivot(['member_role', 'invited_by'])
            ->withTimestamps();
    }

    public function jobPostings(): BelongsToMany
    {
        return $this->belongsToMany(Job::class, 'job_posting_organization', 'organization_id', 'job_posting_id')->withTimestamps();
    }

    public function hasCompletedOnboarding(): bool
    {
        return $this->onboarding_completed_at !== null;
    }

    public function displayName(): string
    {
        return $this->enterprise_name
            ?? $this->contact_name
            ?? $this->email;
    }
}
