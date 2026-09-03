<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserSocialLink extends Model
{
    /**
     * Shared URL validation for web and API social-link writes.
     * Only http/https schemes are allowed.
     */
    public const URL_RULES = ['required', 'string', 'max:2048', 'url:http,https'];

    protected $fillable = [
        'user_id',
        'title',
        'url',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope to order links by sort_order
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order', 'asc');
    }
}
