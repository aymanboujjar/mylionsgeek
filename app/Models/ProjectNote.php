<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectNote extends Model
{
    public const DEFAULT_COLOR = 'bg-amber-50 dark:bg-amber-950/50';

    public const ALLOWED_COLORS = [
        'bg-amber-50 dark:bg-amber-950/50',
        'bg-emerald-50 dark:bg-emerald-950/50',
        'bg-sky-50 dark:bg-sky-950/50',
        'bg-rose-50 dark:bg-rose-950/50',
        'bg-purple-50 dark:bg-purple-950/50',
        'bg-orange-50 dark:bg-orange-950/50',
    ];

    protected $fillable = [
        'title',
        'content',
        'is_pinned',
        'tags',
        'color',
    ];

    protected $casts = [
        'is_pinned' => 'boolean',
        'tags' => 'array',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
