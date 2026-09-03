<?php

namespace App\Http\Middleware;

use App\Models\Attendance;
use App\Models\Formation;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'csrfToken' => csrf_token(),
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            'session' => [
                'id' => $request->session()->getId(),
            ],
            'auth' => [
                'recruiting' => fn () => $request->user()?->recruitingContext(),
                'user' => (function () use ($request) {
                    $user = $request->user();
                    if (! $user) {
                        return null;
                    }

                    if (Schema::hasTable('accesses')) {
                        $user->loadMissing('access');
                    }

                    $user->loadMissing('socialLinks');

                    $rawImage = $user->image ?? null;
                    $avatarUrl = null;
                    if ($rawImage) {
                        $avatarUrl = Str::startsWith($rawImage, ['http://', 'https://'])
                            ? $rawImage
                            : asset('storage/' . ltrim($rawImage, '/'));
                    }

                    // Return user data merged with computed fields WITHOUT mutating/saving them on the model
                    return array_merge($user->toArray(), [
                        'avatarUrl' => $avatarUrl,
                        'isProfileImageMissing' => empty($avatarUrl),
                        'is_organisation_account' => $user->isOrganisationAccount(),
                        'social_links' => $user->socialLinks,
                        'resume' => $user->resume,
                        'resume_url' => $user->resumePublicUrl(),
                        'resume_view_url' => $user->resumeViewUrl(),
                    ]);
                })(),
            ],
            'attendanceWarning' => function () use ($request) {
                $today = Carbon::today();
                $todayString = $today->toDateString();
                $user = $request->user();

                // Don't show attendance reminders on weekends (Saturday = 6, Sunday = 0)
                $dayOfWeek = $today->dayOfWeek;
                if ($dayOfWeek === Carbon::SATURDAY || $dayOfWeek === Carbon::SUNDAY) {
                    return [
                        'hasWarning' => false,
                        'trainings' => [],
                        'date' => $todayString,
                    ];
                }

                if (! $user) {
                    return [
                        'hasWarning' => false,
                        'trainings' => [],
                        'date' => $todayString,
                    ];
                }

                $roles = $user->role;
                if (! is_array($roles)) {
                    $roles = array_filter([$roles]);
                }

                $isCoach = in_array('coach', $roles ?? [], true);
                if (! $isCoach) {
                    return [
                        'hasWarning' => false,
                        'trainings' => [],
                        'date' => $todayString,
                    ];
                }

                $trainings = Formation::query()
                    ->select(['id', 'name', 'start_time', 'end_time'])
                    ->where('user_id', $user->id)
                    ->get();

                if ($trainings->isEmpty()) {
                    return [
                        'hasWarning' => false,
                        'trainings' => [],
                        'date' => $todayString,
                    ];
                }

                $trainingIds = $trainings->pluck('id')->filter()->values();
                if ($trainingIds->isEmpty()) {
                    return [
                        'hasWarning' => false,
                        'trainings' => [],
                        'date' => $todayString,
                    ];
                }

                $attendedToday = Attendance::query()
                    ->whereIn('formation_id', $trainingIds->all(), 'and', false)
                    ->whereDate('attendance_day', '=', $todayString, 'and')
                    ->pluck('formation_id')
                    ->map(fn ($id) => (string) $id)
                    ->all();

                $attendedLookup = array_flip($attendedToday);

                $parseDate = static function (?string $value): ?string {
                    if (empty($value) || strtoupper($value) === 'NULL') {
                        return null;
                    }

                    try {
                        return Carbon::parse($value)->toDateString();
                    } catch (\Throwable) {
                        return null;
                    }
                };

                $missingTrainings = $trainings->filter(function ($training) use ($todayString, $attendedLookup, $parseDate) {
                    $start = $parseDate($training->start_time ?? null);
                    $end = $parseDate($training->end_time ?? null);

                    // Only show reminders for active trainings (between start_time and end_time)
                    if ($start && $start > $todayString) {
                        return false;
                    }

                    if ($end && $end < $todayString) {
                        return false;
                    }

                    return ! array_key_exists((string) $training->id, $attendedLookup);
                })->map(function ($training) use ($parseDate) {
                    return [
                        'id' => (string) $training->id,
                        'name' => $training->name,
                        'start_time' => $parseDate($training->start_time ?? null),
                        'end_time' => $parseDate($training->end_time ?? null),
                    ];
                })->values();

                return [
                    'hasWarning' => $missingTrainings->isNotEmpty(),
                    'trainings' => $missingTrainings,
                    'date' => $todayString,
                ];
            },
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
            'conversations' => fn () => $request->session()->get('conversations'),
            'conversation' => fn () => $request->session()->get('conversation'),
            'messages' => fn () => $request->session()->get('messages'),
            'message' => fn () => $request->session()->get('message'),
            'unread_count' => fn () => $request->session()->get('unread_count'),
            'posts' => fn () => $request->session()->get('posts'),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
