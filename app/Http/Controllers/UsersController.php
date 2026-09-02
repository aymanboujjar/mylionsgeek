<?php

namespace App\Http\Controllers;

use App\Models\Formation;
use Inertia\Inertia;
use App\Http\Controllers\Controller;
use App\Mail\CompleteUserProfile;
use App\Mail\UserWelcomeMail;
use App\Jobs\SendNewsletterEmail;
use App\Models\AttendanceListe;
use App\Models\Computer;
use App\Models\NewsletterEmail;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Models\Contract;
use App\Models\Follower;
use App\Models\Like;
use App\Models\Medical;
use App\Models\Note;
use App\Models\Post;
use App\Models\Project;
use App\Models\Reservation;
use App\Support\PostMentionResolver;
use App\Models\UserProject;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Services\ExportService;
use App\Services\DisciplineService;
use App\Services\UserProfileStatsService;

class UsersController extends Controller
{
    public function index()
    {
        $allUsers = User::query()
            ->where('role', '!=', 'admin')
            ->orderByDesc('created_at')
            ->get()
            ->filter(fn (User $user) => ! $user->isRecruiter())
            ->values();

        $allFormation = Formation::with(['coach:id,name'])->orderBy('created_at', 'desc')->get();

        return Inertia::render('admin/users/index', [
            'users' => $allUsers->map(fn (User $user) => array_merge($user->toArray(), [
                'resume_view_url' => $user->resumeViewUrl(),
            ])),
            'trainings' => $allFormation,
        ]);
    }


    public function export(Request $request)
    {
        $requestedFields = array_filter(array_map('trim', explode(',', (string) $request->query('fields', 'name,email,cin'))));

        $user = $request->user();
        $roles = is_array($user->role) ? $user->role : [$user->role];

        if (! in_array('admin', $roles, true) && ! in_array('super_admin', $roles, true)) {
            $requestedFields = array_values(array_diff($requestedFields, ['cin', 'phone', 'role']));
        }

        $fieldMap = [
            'id' => 'id',
            'name' => 'name',
            'email' => 'email',
            'cin' => 'cin',
            'phone' => 'phone',
            'status' => 'status',
            'role' => 'role',
            'formation' => 'formation',
            'access_studio' => 'access_studio',
            'access_cowork' => 'access_cowork',
            'access_scan' => 'access_scan',
        ];

        $query = User::query();
        if ($request->filled('role')) {
            $query->where('role', $request->query('role'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('formation_id')) {
            $query->where('formation_id', $request->query('formation_id'));
        }

        return ExportService::export($query, $requestedFields, [
            'fieldMap' => $fieldMap,
            'defaultFields' => ['name', 'email', 'cin'],
            'relationships' => ['formation'],
            'filename' => 'students_export_' . now()->format('Y_m_d_H_i_s'),
            'transformers' => [
                'formation' => function ($user) {
                    return optional($user->formation)->name ?? '';
                },
                'access_studio' => function ($user) {
                    return (string) $user->access_studio === '1' || $user->access_studio === 1 ? 'Yes' : 'No';
                },
                'access_cowork' => function ($user) {
                    return (string) $user->access_cowork === '1' || $user->access_cowork === 1 ? 'Yes' : 'No';
                },
                'access_scan' => function ($user) {
                    return (string) $user->access_scan === '1' || $user->access_scan === 1 ? 'Yes' : 'No';
                },
            ],
        ]);
    }
    //! edit sunction
    public function show(Request $request, User $user)
    {
        $user->load(['formation']);

        if (Schema::hasTable('accesses')) {
            $user->load(['access']);
        }

        // Online status check (last 5 minutes)
        $isOnline = $user->last_online
            ? Carbon::parse($user->last_online)->gt(now()->subMinutes(5))
            : false;

        // Get assigned computer
        $assignedComputer = Schema::hasTable('computers')
            ? Computer::query()->where('user_id', $user->id)->latest('start')->first()
            : null;

        // Paginated data
        $reservations = $this->getReservations($user, $request);
        $posts = $this->getPosts($user);
        $absences = $this->getAbsences($user, $request);

        // Calculate discipline score using DisciplineService
        $disciplineService = new DisciplineService();
        $discipline = $disciplineService->calculateDisciplineScore($user);

        // Get all formations
        $allFormations = Formation::query()->orderByDesc('created_at')->get();

        $roles = [
            'student',
            'admin',
            'studio_responsable',
            'coach',
            'pro',
            'moderateur',
            'recruiter',
            'coworker'
        ];
        $profileStats = app(UserProfileStatsService::class)->getStats($user);
        $userPayload = array_merge(
            $this->formatUserPayload($user, $isOnline),
            $profileStats
        );

        return Inertia::render('admin/users/[id]', [
            'user' => $userPayload,
            'roles' => $roles,
            'trainings' => $allFormations,
            'assignedComputer' => $this->formatComputer($assignedComputer),
            'posts' => $posts,
            'reservations' => $reservations,
            'discipline' => $discipline,
            'absences' => $absences['paginated'],
            'recentAbsences' => $absences['recent'],
        ]);
    }


    private function getReservations(User $user, Request $request)
    {
        $reservations = Reservation::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->paginate(10, ['*'], 'reservations_page', $request->get('reservations_page', 1))
            ->onEachSide(1);

        return [
            'data' => $reservations->map(fn($r) => [
                'id' => $r->id,
                'title' => $r->title,
                'description' => $r->description,
                'day' => $r->day,
                'start' => $r->start,
                'end' => $r->end,
                'type' => $r->type,
                'approved' => $r->approved,
                'canceled' => $r->canceled,
                'passed' => $r->passed,
                'created_at' => (string) $r->created_at,
            ]),
            'meta' => $this->getPaginationMeta($reservations),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @param  array{followed_ids?: int[], reposted_post_ids?: int[]}  $context
     * @return array<string, mixed>
     */
    protected function mapPostForFeed(Post $post, User $authUser, array $context = []): array
    {
        $interactionPost = $post;
        $isLikedByCurrentUser = $interactionPost->relationLoaded('likes')
            ? $interactionPost->likes->isNotEmpty()
            : false;

        $interactionPostId = (int) $interactionPost->id;
        $isRepostedByCurrentUser = isset($context['reposted_post_ids'])
            ? in_array($interactionPostId, $context['reposted_post_ids'], true)
            : DB::table('reposts_posts')
                ->where('user_id', $authUser->id)
                ->where('post_id', $interactionPostId)
                ->exists();

        return [
            'user_id' => $post->user_id,
            'user_name' => $post->user->name,
            'user_image' => $post->user->image,
            'user_last_online' => $post->user->last_online,
            'user_status' => $post->user->status,
            'user_formation' => $post->user->formation?->name,

            'id' => $post->id,
            'type' => 'post',
            'description' => $post->description,
            'mention_user_ids' => PostMentionResolver::mapTokensToUserIds($post->description),
            'images' => $post->images,
            'interaction_post_id' => $interactionPostId,
            'can_repost' => true,

            'likes_count' => $interactionPost->likes_count ?? 0,
            'comments_count' => $interactionPost->comments_count ?? 0,
            'reposts_count' => $interactionPost->reposts_count ?? 0,

            'is_liked_by_current_user' => $isLikedByCurrentUser,
            'is_reposted_by_current_user' => $isRepostedByCurrentUser,
            'current_user_repost_description' => $isRepostedByCurrentUser
                ? (string) (($context['reposted_descriptions'] ?? [])[$interactionPostId] ?? '')
                : null,

            'created_at' => $post->created_at,

            'is_following' => isset($context['followed_ids'])
                ? in_array((int) $post->user_id, $context['followed_ids'], true)
                : $authUser->following()->where('followed_id', $post->user_id)->exists(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @param  array{followed_ids?: int[], reposted_post_ids?: int[]}  $context
     * @return array<string, mixed>
     */
    protected function mapRepostForFeed(object $repostRow, Post $originalPost, User $reposter, User $authUser, array $context = []): array
    {
        $repostCreatedAt = null;
        try {
            if (!empty($repostRow->created_at)) {
                $repostCreatedAt = Carbon::parse((string) $repostRow->created_at)->toDateTimeString();
            }
        } catch (\Throwable $e) {
            $repostCreatedAt = (string) ($repostRow->created_at ?? null);
        }

        // Likes live on the original post; repost items should reflect that state too.
        $isLikedByCurrentUser = $originalPost->relationLoaded('likes')
            ? $originalPost->likes->isNotEmpty()
            : false;

        return [
            'user_id' => (int) $reposter->id,
            'user_name' => $reposter->name,
            'user_image' => $reposter->image,
            'user_last_online' => $reposter->last_online,
            'user_status' => $reposter->status,
            'user_formation' => $reposter->formation?->name,

            // Use a string id to avoid collisions with posts.id
            'id' => 'repost-' . (int) ($repostRow->id ?? 0),
            'type' => 'repost',
            'description' => (string) ($repostRow->description ?? ''),
            'mention_user_ids' => PostMentionResolver::mapTokensToUserIds((string) ($repostRow->description ?? '')),
            'images' => [],
            'interaction_post_id' => (int) $originalPost->id,
            'can_repost' => false,
            'repost_of' => $this->mapPostForFeed($originalPost, $authUser, $context),

            // Always show original stats for a repost item
            'likes_count' => (int) ($originalPost->likes_count ?? 0),
            'comments_count' => (int) ($originalPost->comments_count ?? 0),
            'reposts_count' => (int) ($originalPost->reposts_count ?? 0),

            'is_liked_by_current_user' => $isLikedByCurrentUser,
            'is_reposted_by_current_user' => isset($context['reposted_post_ids'])
                ? in_array((int) $originalPost->id, $context['reposted_post_ids'], true)
                : DB::table('reposts_posts')
                    ->where('user_id', $authUser->id)
                    ->where('post_id', (int) $originalPost->id)
                    ->exists(),
            'repost_pivot_id' => (int) ($repostRow->id ?? 0),
            'created_at' => $repostCreatedAt,
            'is_following' => isset($context['followed_ids'])
                ? in_array((int) $reposter->id, $context['followed_ids'], true)
                : $authUser->following()->where('followed_id', $reposter->id)->exists(),
        ];
    }

    /**
     * @return array{followed_ids: int[], reposted_post_ids: int[], reposted_descriptions: array<int, string>}
     */
    protected function buildFeedMappingContext(User $authUser): array
    {
        $repostedRows = DB::table('reposts_posts')
            ->where('user_id', $authUser->id)
            ->get(['post_id', 'description']);

        $repostedDescriptions = [];
        foreach ($repostedRows as $row) {
            $repostedDescriptions[(int) $row->post_id] = (string) ($row->description ?? '');
        }

        return [
            'followed_ids' => $authUser->following()
                ->pluck('followed_id')
                ->map(fn ($id) => (int) $id)
                ->all(),
            'reposted_post_ids' => array_keys($repostedDescriptions),
            'reposted_descriptions' => $repostedDescriptions,
        ];
    }

    protected function feedItemSortKey(array $item): string
    {
        $type = ($item['type'] ?? 'post') === 'repost' ? 'repost' : 'post';
        $createdAt = Carbon::parse($item['created_at'])->format('Y-m-d H:i:s.u');
        $sortId = $type === 'repost'
            ? (int) ($item['repost_pivot_id'] ?? (int) str_replace('repost-', '', (string) ($item['id'] ?? '0')))
            : (int) $item['id'];

        return sprintf('%s|%s|%010d', $createdAt, $type, $sortId);
    }

    protected function encodeFeedCursor(array $item): string
    {
        return base64_encode($this->feedItemSortKey($item));
    }

    protected function decodeFeedCursor(?string $cursor): ?string
    {
        if (!$cursor) {
            return null;
        }

        $decoded = base64_decode($cursor, true);

        return $decoded !== false ? $decoded : null;
    }

    protected function isFeedItemOlderThanCursor(array $item, string $cursor): bool
    {
        $decodedCursor = $this->decodeFeedCursor($cursor);

        if (!$decodedCursor) {
            return true;
        }

        return $this->feedItemSortKey($item) < $decodedCursor;
    }

    protected function applyFeedCursorToPostQuery($query, ?string $cursor): void
    {
        $decodedCursor = $this->decodeFeedCursor($cursor);

        if (!$decodedCursor) {
            return;
        }

        [$createdAt] = explode('|', $decodedCursor, 2);

        $query->where('created_at', '<=', $createdAt);
    }

    protected function applyFeedCursorToRepostQuery($query, ?string $cursor): void
    {
        $decodedCursor = $this->decodeFeedCursor($cursor);

        if (!$decodedCursor) {
            return;
        }

        [$createdAt] = explode('|', $decodedCursor, 2);

        $query->where('created_at', '<=', $createdAt);
    }

    /**
     * Paginated main feed (posts + reposts merged). Default 10 items per page.
     *
     * @return array{posts: array<int, array<string, mixed>>, next_cursor: ?string, has_more: bool}
     */
    public function getPostsPaginated(int $perPage = 10, ?string $cursor = null): array
    {
        $authUser = Auth::user();

        if (!$authUser) {
            return ['posts' => [], 'next_cursor' => null, 'has_more' => false];
        }

        $fetchLimit = $perPage * 5;
        $context = $this->buildFeedMappingContext($authUser);

        $postQuery = Post::with([
            'user.formation',
            'likes' => function ($query) use ($authUser) {
                $query->where('user_id', $authUser->id);
            },
        ])
            ->withCount(['likes', 'comments', 'reposts'])
            ->where(function ($q) {
                $q->whereNull('is_hidden')->orWhere('is_hidden', false);
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $this->applyFeedCursorToPostQuery($postQuery, $cursor);

        /** @var Collection<int, Post> $postModels */
        $postModels = $postQuery->limit($fetchLimit)->get();
        $postItems = $postModels->map(fn (Post $post) => $this->mapPostForFeed($post, $authUser, $context));

        $repostRows = collect();
        $repostItems = collect();

        $repostQuery = DB::table('reposts_posts')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $this->applyFeedCursorToRepostQuery($repostQuery, $cursor);
        $repostRows = $repostQuery->limit($fetchLimit)->get();

        if ($repostRows->isNotEmpty()) {
            $originalIds = $repostRows->pluck('post_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
            $reposterIds = $repostRows->pluck('user_id')->map(fn ($id) => (int) $id)->unique()->values()->all();

            $originalPosts = Post::with(['user.formation'])
                ->with(['likes' => function ($q) use ($authUser) {
                    $q->where('user_id', $authUser->id);
                }])
                ->withCount(['likes', 'comments', 'reposts'])
                ->where(function ($q) {
                    $q->whereNull('is_hidden')->orWhere('is_hidden', false);
                })
                ->whereIn('id', $originalIds)
                ->get()
                ->keyBy('id');

            $reposters = User::with('formation')
                ->whereIn('id', $reposterIds)
                ->get()
                ->keyBy('id');

            $repostItems = $repostRows
                ->map(function ($row) use ($originalPosts, $reposters, $authUser, $context) {
                    $original = $originalPosts[(int) $row->post_id] ?? null;
                    $reposter = $reposters[(int) $row->user_id] ?? null;
                    if (!$original || !$reposter) {
                        return null;
                    }

                    return $this->mapRepostForFeed($row, $original, $reposter, $authUser, $context);
                })
                ->filter()
                ->values();
        }

        $merged = $postItems
            ->concat($repostItems)
            ->filter(fn ($item) => $item && isset($item['created_at']) && $item['created_at'])
            ->sortByDesc(fn ($item) => $this->feedItemSortKey($item))
            ->values();

        if ($cursor) {
            $merged = $merged
                ->filter(fn ($item) => $this->isFeedItemOlderThanCursor($item, $cursor))
                ->values();
        }

        $items = $merged->take($perPage)->values();
        $last = $items->last();

        $hasMore = $merged->count() > $perPage
            || $postModels->count() >= $fetchLimit
            || $repostRows->count() >= $fetchLimit;

        return [
            'posts' => $items->all(),
            'next_cursor' => is_array($last) ? $this->encodeFeedCursor($last) : null,
            'has_more' => $hasMore && $items->isNotEmpty(),
        ];
    }

    public function getPosts($user = null)
    {
        $authUser = Auth::user();

        if (!$authUser) {
            return ['posts' => collect()];
        }

        $dataPosts = Post::with([
            'user',
            'likes',
            'comments',
            'likes' => function ($query) use ($authUser) {
                $query->where('user_id', $authUser->id);
            },
        ])
            ->withCount(['likes', 'comments', 'reposts'])
            ->where(function ($q) {
                $q->whereNull('is_hidden')->orWhere('is_hidden', false);
            })
            ->orderByDesc('created_at');

        if ($user) {
            $dataPosts = $dataPosts->where('user_id', $user->id);
        }

        /** @var Collection<int, Post> $postModels */
        $postModels = $dataPosts->get();

        $postItems = $postModels->map(fn (Post $post) => $this->mapPostForFeed($post, $authUser));

        // For the main feed, merge repost rows so reposts appear as feed items.
        if (!$user) {
            $repostRows = DB::table('reposts_posts')
                ->orderByDesc('created_at')
                ->limit(60)
                ->get();

            if ($repostRows->isNotEmpty()) {
                $originalIds = $repostRows->pluck('post_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
                $reposterIds = $repostRows->pluck('user_id')->map(fn ($id) => (int) $id)->unique()->values()->all();

                $originalPosts = Post::with(['user', 'comments'])
                    ->with(['likes' => function ($q) use ($authUser) {
                        $q->where('user_id', $authUser->id);
                    }])
                    ->withCount(['likes', 'comments', 'reposts'])
                    ->where(function ($q) {
                        $q->whereNull('is_hidden')->orWhere('is_hidden', false);
                    })
                    ->whereIn('id', $originalIds)
                    ->get()
                    ->keyBy('id');

                $reposters = User::with('formation')
                    ->whereIn('id', $reposterIds)
                    ->get()
                    ->keyBy('id');

                $repostItems = $repostRows
                    ->map(function ($row) use ($originalPosts, $reposters, $authUser) {
                        $original = $originalPosts[(int) $row->post_id] ?? null;
                        $reposter = $reposters[(int) $row->user_id] ?? null;
                        if (!$original || !$reposter) {
                            return null;
                        }

                        return $this->mapRepostForFeed($row, $original, $reposter, $authUser);
                    })
                    ->filter()
                    ->values();

                $postItems = $postItems->concat($repostItems)->values();
            }
        }

        $sorted = $postItems
            ->filter(fn ($item) => $item && isset($item['created_at']) && $item['created_at'])
            ->sortByDesc('created_at')
            ->values();

        return ['posts' => $sorted];
    }

    /**
     * Posts for a profile user (feed-shaped). Optional limit for preview; total is always full count.
     *
     * @return array{posts: \Illuminate\Support\Collection, total: int}
     */
    public function getPostsForProfileUser(int $profileUserId, ?int $limit = null): array
    {
        $authUser = Auth::user();

        $query = Post::with([
            'user',
            'likes',
            'comments',
            'likes' => function ($q) use ($authUser) {
                $q->where('user_id', $authUser->id);
            },
        ])
            ->withCount(['likes', 'comments', 'reposts'])
            ->where('user_id', $profileUserId)
            ->where(function ($q) {
                $q->whereNull('is_hidden')->orWhere('is_hidden', false);
            })
            ->orderByDesc('created_at');

        if ($limit !== null) {
            $query->limit($limit);
        }

        $dataPosts = $query->get();
        $posts = $dataPosts->map(fn (Post $post) => $this->mapPostForFeed($post, $authUser));
        $total = (int) Post::query()
            ->where('user_id', $profileUserId)
            ->where(function ($q) {
                $q->whereNull('is_hidden')->orWhere('is_hidden', false);
            })
            ->toBase()
            ->count();

        return [
            'posts' => $posts,
            'total' => $total,
        ];
    }




    private function getAbsences(User $user, Request $request)
    {
        $absencesQuery = AttendanceListe::query()
            ->where('user_id', $user->id)
            ->where(function ($q) {
                $q->whereRaw('LOWER(TRIM(morning)) = ?', ['absent'])
                    ->orWhereRaw('LOWER(TRIM(lunch)) = ?', ['absent'])
                    ->orWhereRaw('LOWER(TRIM(evening)) = ?', ['absent']);
            })
            ->orderByDesc('attendance_day')
            ->orderByDesc('updated_at');

        $paginated = $absencesQuery->paginate(10, ['*'], 'absences_page', $request->get('absences_page', 1))
            ->onEachSide(1);

        $recent = (clone $absencesQuery)->limit(5)->get();

        // Get notes for absences
        $attendanceIds = $recent->pluck('attendance_id')
            ->merge($paginated->pluck('attendance_id'))
            ->unique();

        $notesByAttendance = Note::query()
            ->whereIn('attendance_id', $attendanceIds->values()->all(), 'and', false)
            ->get()
            ->groupBy('attendance_id');

        $formatter = fn($row) => [
            'attendance_id' => $row->attendance_id,
            'date' => $row->attendance_day,
            'morning' => strtolower((string) $row->morning),
            'lunch' => strtolower((string) $row->lunch),
            'evening' => strtolower((string) $row->evening),
            'notes' => $notesByAttendance->get($row->attendance_id, collect())->pluck('note')->values(),
        ];

        return [
            'paginated' => [
                'data' => $paginated->map($formatter),
                'meta' => $this->getPaginationMeta($paginated),
            ],
            'recent' => $recent->map($formatter),
        ];
    }

    // Discipline calculation is now handled by DisciplineService

    private function formatUserPayload(User $user, bool $isOnline)
    {
        $payload = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'cin' => $user->cin,
            'status' => $user->status,
            'formation_id' => $user->formation_id,
            'image' => $user->image,
            'cover' => $user->cover,
            'about' => $user->about,
            'speciality' => $user->speciality,
            'socials' => $user->socials,
            'last_online' => $user->last_online,
            'is_online' => $isOnline,
            'formation_name' => $user->formation?->name,
            'resume' => $user->resume,
            'resume_view_url' => $user->resumeViewUrl(),
            'role' => $user->role,
        ];

        // Debug logging
        Log::info('User payload for user ' . $user->id, [
            'phone' => $user->phone,
            'status' => $user->status,
            'formation_id' => $user->formation_id,
        ]);

        return $payload;
    }

    private function formatComputer($computer)
    {
        if (!$computer) {
            return null;
        }

        return [
            'reference' => $computer->reference,
            'mark' => $computer->mark,
            'cpu' => $computer->cpu,
            'gpu' => $computer->gpu,
            'start' => (string) $computer->start,
            'end' => (string) $computer->end,
        ];
    }

    private function getPaginationMeta($paginator)
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }

    // Return user notes as JSON for modal consumption
    public function notes(User $user)
    {
        $notes = Note::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['note', 'author', 'created_at'])
            ->map(function ($row) {
                return [
                    'note' => (string) $row->note,
                    'author' => (string) ($row->author ?? 'Unknown'),
                    'created_at' => (string) $row->created_at,
                ];
            })
            ->values();

        return response()->json(['notes' => $notes]);
    }

    // Store a new note for a user from the admin modal
    public function storeNote(Request $request, User $user)
    {
        $validated = $request->validate([
            'note' => 'required|string|max:1000',
        ]);

        Note::create([
            'user_id' => (int) $user->id,
            'attendance_id' => null,
            'note' => $validated['note'],
            'author' => (Auth::check() ? (Auth::user()->name ?? 'Admin') : 'Admin'),
        ]);

        return response()->json(['status' => 'ok']);
    }

    // Documents API
    public function documents(User $user)
    {
        $toUrl = function ($path) {
            $p = (string) $path;
            if ($p === '') {
                return null;
            }
            if (str_starts_with($p, 'http://') || str_starts_with($p, 'https://')) {
                return $p;
            }
            // If already a web path like "/storage/...", return as-is (legacy rows)
            if (str_starts_with($p, '/storage/')) {
                return $p;
            }
            // Default: map storage path to public URL
            return Storage::url($p);
        };

        $contracts = Contract::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->get(['id', 'contract', 'type', 'created_at'])
            ->map(function ($c) {
                return [
                    'id' => (int) $c->id,
                    'name' => (string) ($c->type ?: 'Contract'),
                    // Always attempt to generate a URL; legacy rows may already store '/storage/...'
                    'url' => (function ($path) {
                        $p = (string) $path;
                        if ($p === '') return null;
                        if (str_starts_with($p, 'http://') || str_starts_with($p, 'https://')) return $p;
                        if (str_starts_with($p, '/storage/')) return $p;
                        return Storage::url($p);
                    })($c->contract),
                    'kind' => 'contract',
                    'created_at' => (string) $c->created_at,
                ];
            });

        $medicals = Medical::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->get(['id', 'mc_document', 'description', 'created_at'])
            ->map(function ($m) {
                return [
                    'id' => (int) $m->id,
                    'name' => (string) ($m->description ?: 'Medical certificate'),
                    'url' => (function ($path) {
                        $p = (string) $path;
                        if ($p === '') return null;
                        if (str_starts_with($p, 'http://') || str_starts_with($p, 'https://')) return $p;
                        if (str_starts_with($p, '/storage/')) return $p;
                        return Storage::url($p);
                    })($m->mc_document),
                    'kind' => 'medical',
                    'created_at' => (string) $m->created_at,
                ];
            });

        return response()->json([
            'contracts' => $contracts->values(),
            'medicals' => $medicals->values(),
        ]);
    }

    public function uploadDocument(Request $request, User $user)
    {
        $validated = $request->validate([
            'kind' => 'required|string|in:contract,medical',
            'file' => 'required|file|max:10240',
            'name' => 'nullable|string|max:255',
            'type' => 'nullable|string|max:100',
        ]);

        $path = $request->file('file')->store('documents', 'public');

        if ($validated['kind'] === 'contract') {
            Contract::create([
                'user_id' => $user->id,
                'contract' => $path,
                'type' => $validated['type'] ?? ($validated['name'] ?? 'Contract'),
                'reservation_id' => null,
            ]);
        } else {
            Medical::create([
                'user_id' => $user->id,
                'mc_document' => $path,
                'description' => $validated['name'] ?? 'Medical certificate',
                'author' => (Auth::check() ? (Auth::user()->name ?? 'Admin') : 'Admin'),
            ]);
        }

        return response()->json(['status' => 'ok']);
    }

    // Stream a stored document via controller to avoid direct /storage access issues
    public function viewDocument(Request $request, User $user, string $kind, int $doc)
    {
        // Fetch by document id only to handle legacy rows with mismatched user_id types
        if ($kind === 'contract') {
            $row = Contract::query()->whereKey($doc)->firstOrFail();
            $path = (string) $row->contract;
        } else {
            $row = Medical::query()->whereKey($doc)->firstOrFail();
            $path = (string) $row->mc_document;
        }

        // Normalize possible stored paths and resolve to an actual file on public disk
        if (str_starts_with($path, '/storage/')) {
            $path = ltrim(substr($path, strlen('/storage/')), '/');
        }
        if (preg_match('/^https?:\/\//i', $path)) {
            return redirect()->away($path);
        }

        $candidates = [];
        $base = ltrim($path, '/');
        // as-is
        $candidates[] = $base;
        // try common directories for legacy rows that only stored filename
        $candidates[] = 'documents/' . basename($base);
        if ($kind === 'contract') {
            $candidates[] = 'contracts/' . basename($base);
        } else {
            $candidates[] = 'medicals/' . basename($base);
        }

        $resolved = null;
        foreach ($candidates as $candidate) {
            if (Storage::disk('public')->exists($candidate)) {
                $resolved = $candidate;
                break;
            }
        }

        if ($resolved === null) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $fullPath = storage_path('app/public/' . ltrim($resolved, '/'));
        if (!is_file($fullPath)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return response()->file($fullPath);
    }

    /**
     * Stream the user's CV for inline viewing in a browser tab.
     */
    public function viewResume(Request $request, User $user): StreamedResponse|Response
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            abort(401);
        }

        $actorRoles = is_array($actor->role) ? $actor->role : array_filter([(string) $actor->role]);
        $actorRolesLower = array_map('strtolower', $actorRoles);
        $canViewOthers = ! empty(array_intersect($actorRolesLower, [
            'admin',
            'super_admin',
            'moderateur',
            'coach',
            'studio_responsable',
            'responsable_studio',
            'recruiter',
        ]));

        if (! $canViewOthers && (int) $actor->id !== (int) $user->id) {
            abort(403);
        }

        $fullPath = $user->resolveResumeAbsolutePath();
        if (! $fullPath || ! is_readable($fullPath)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION) ?: 'pdf');
        $mime = match ($ext) {
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            default => 'application/octet-stream',
        };

        $safeName = preg_replace('/[^a-z0-9._-]+/i', '_', (string) $user->name).'_cv.'.$ext;
        $asciiName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $safeName);

        // PDF: stream with inline headers so the browser opens the built-in viewer in a new tab.
        if ($ext === 'pdf') {
            return response()->stream(function () use ($fullPath) {
                $handle = fopen($fullPath, 'rb');
                if ($handle === false) {
                    return;
                }
                fpassthru($handle);
                fclose($handle);
            }, 200, [
                'Content-Type' => $mime,
                'Content-Disposition' => 'inline; filename="'.$asciiName.'"',
                'Cache-Control' => 'private, max-age=3600',
            ]);
        }

        return response()
            ->file($fullPath, ['Content-Type' => $mime])
            ->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $safeName, $asciiName);
    }

    public function update(Request $request, User $user)
    {
        $actor = $request->user();
        $actorRoles = is_array($actor->role) ? $actor->role : array_filter([(string) $actor->role]);
        $actorRolesLower = array_map('strtolower', $actorRoles);
        $canEditOthers = ! empty(array_intersect($actorRolesLower, [
            'admin',
            'super_admin',
            'moderateur',
            'coach',
            'studio_responsable',
            'responsable_studio',
        ]));

        if (! $canEditOthers && (int) $actor->id !== (int) $user->id) {
            abort(403, 'You can only update your own profile.');
        }

        $validated = $request->validate([
            'name' => 'nullable|string',
            'email' => 'nullable|email|unique:users,email,' . $user->id,
            'roles' => 'nullable|array',
            'roles.*' => 'string|in:student,coach,admin,super_admin,moderateur,studio_responsable,responsable_studio,coworker,pro,recruiter',
            'status' => 'nullable|string',
            'formation_id' => 'nullable|integer|exists:formations,id',
            'phone' => 'nullable|string',
            'cin' => 'nullable|string',
            'speciality' => 'nullable|string|max:255',
            'image' => 'nullable|image',
            'cover' => 'nullable|image', // <-- allow cover image
            'resume' => 'nullable|file|mimes:pdf,doc,docx|max:10240',
            'access_cowork' => 'nullable|integer|in:0,1',
            'access_studio' => 'nullable|integer|in:0,1',
            'access_scan' => 'nullable|integer|in:0,1',
        ]);

            if ($request->has('formation_id')) {
            $formation = Formation::query()->whereKey($request->formation_id)->first();
            // dd($formation->category);
            $user->field = $formation->category;
            $user->save();
        }
        if ($request->hasFile('image')) {
            $file = $request->file('image');

            // Generate a unique hashed filename (like 68fb430843ce2.jpg)
            $filename = $file->hashName();

            // Move the file to public/img/profile/
            $file->move(public_path('/storage/img/profile'), $filename);

            // Store only the filename in database
            $validated['image'] = $filename;
        }
        if ($request->hasFile('cover')) {
            $coverFile = $request->file('cover');
            $coverName = $coverFile->hashName();
            $coverFile->move(public_path('/storage/img/cover'), $coverName);
            $validated['cover'] = $coverName;
        }

        if ($request->hasFile('resume')) {
            $validated['resume'] = $user->storeResumeFromUpload($request->file('resume'));
        }

        // Map roles (array) to 'role' JSON column, lowercased.
        // SECURITY: only privileged actors may change roles. A self-updating student
        // must never be able to escalate their own role. Without this gate the endpoint
        // is a broken-access-control / mass-assignment privilege escalation (a student
        // can POST roles[]=admin to /students/update/{their-own-id} and become admin).
        unset($validated['roles'], $validated['role']);
        if ($request->has('roles') && $canEditOthers) {
            $roles = $request->input('roles');
            if (is_array($roles)) {
                $validated['role'] = array_values(array_map(function ($r) {
                    return strtolower((string) $r);
                }, $roles));
            }
        }

        $isSelfUpdate = (int) $actor->id === (int) $user->id;
        $currentStatusNormalized = strtolower(trim((string) $user->status));

        if ($isSelfUpdate && ! $canEditOthers) {
            if ($currentStatusNormalized === 'studying') {
                unset($validated['status']);
            } elseif (isset($validated['status'])) {
                $requestedStatus = strtolower(trim((string) $validated['status']));
                if ($requestedStatus === 'studying') {
                    unset($validated['status']);
                } else {
                    $studentAllowed = ['working', 'internship', 'unemployed', 'freelancing', 'certified', 'left'];
                    if (! in_array($requestedStatus, $studentAllowed, true)) {
                        unset($validated['status']);
                    }
                }
            }
        }

        $previousStatus = $user->status;

        $privileged = [];
        foreach (['role', 'access_cowork', 'access_studio', 'access_scan'] as $field) {
            if (array_key_exists($field, $validated)) {
                $privileged[$field] = $validated[$field];
                unset($validated[$field]);
            }
        }

        $user->update($validated);

        if ($canEditOthers && $privileged !== []) {
            $user->forceFill($privileged)->save();
        }

        // When an admin manually certifies a user, reset the LinkedIn modal gate fields
        // so the share prompt appears on their next login, and ensure a share token exists.
        // Uses forceFill() because these fields are not in $fillable.
        if (isset($validated['status']) && $validated['status'] === 'Certified' && $previousStatus !== 'Certified') {
            $user->forceFill([
                'linkedin_share_prompted_at' => null,
                'linkedin_share_dismissed_at' => null,
                'linkedin_shared_at' => null,
                'certified_at' => $user->certified_at ?? now(),
                'certificate_share_token' => $user->certificate_share_token ?: Str::random(48),
            ])->save();
        }

        return redirect()->back()->with('success', 'User updated successfully');
    }
    public function updateAccountStatus(Request $request, User $user)
    {
        $validated = $request->validate([
            'account_state' => 'required|integer|in:0,1'
        ]);

        $user->forceFill([
            'account_state' => $validated['account_state'],
        ])->save();
        // dd($user->account_state , $request->account_state);

        return redirect()->back()->with('success', 'User account status updated successfully');
    }

    //! store function
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string',
            'email' => 'required|string|email|unique:users,email',
            'password' => 'nullable|string|confirmed', // expects password_confirmation
            'phone' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg', // Or 'nullable|string' if not a file
            'status' => 'nullable|string', // adjust allowed values as needed
            'cin' => 'nullable|string', // National ID, if applicable
            'formation_id' => 'required|exists:formations,id', // Assumes foreign key to formations table
            'access_studio' => 'required|integer|in:0,1', // Assumes foreign key to formations table
            'access_cowork' => 'required|integer|in:0,1', // Assumes foreign key to formations table
            'roles' => 'required|array|min:1',
            'roles.*' => 'required|string',
            'entreprise' => 'nullable|string', // Assumes foreign key to formations table
        ]);
        $existing = User::query()->where('email', $validated['email'])->first();
        if ($existing) {
            return Inertia::render('admin/users/partials/Header', [
                'message' => 'this email already exist'
            ]);
        }
        if ($request->hasFile('image')) {
            $file = $request->file('image');

            // Generate a unique hashed filename (like 68fb430843ce2.jpg)
            $filename = $file->hashName();

            // Move the file to public/img/profile/
            $file->move(public_path('/storage/img/profile'), $filename);

            // Store only the filename in database
            $validated['image'] = $filename;
        }
        $plainPassword = Str::random(12);
        $lastUser = User::orderBy('id', 'desc')->first();
        // dd($lastUser->id);
        $user = new User();
        $user->forceFill([
            'id' => $lastUser->id + 1,
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($plainPassword),
            'phone' => $validated['phone'] ?? null,
            'image' => $validated['image'] ?? null,
            'status' => $validated['status'] ?? null,
            'cin' => $validated['cin'] ?? null,
            'formation_id' => $validated['formation_id'],
            'account_state' => $validated['account_state'] ?? 'active',
            'access_studio' => $validated['access_studio'],
            'access_cowork' => $validated['access_cowork'],
            'role' => $validated['roles'],
            'entreprise' => $validated['entreprise'] ?? null,
            'invite_source' => 'admin',
            'remember_token' => null,
            'email_verified_at' => null,
        ])->save();

        $plainToken = $user->issueActivationToken();
        $link = URL::temporarySignedRoute(
            'user.complete-profile',
            now()->addHours(User::ACTIVATION_TTL_HOURS),
            ['token' => $plainToken]
        );
        Mail::to($user->email)->send(new UserWelcomeMail($user, $link));

        // dd($user);

        return redirect()->back()->with('success', 'User updated successfully');
    }

    // Lightweight JSON for modal: discipline + all absences
    public function attendanceSummary(User $user)
    {
        $absencesQuery = AttendanceListe::query()
            ->where('user_id', $user->id)
            ->where(function ($q) {
                $q->where('morning', 'absent')
                    ->orWhere('lunch', 'absent')
                    ->orWhere('evening', 'absent');
            })
            ->orderByDesc('attendance_day')
            ->orderByDesc('updated_at');

        $all = $absencesQuery->get(['attendance_id', 'attendance_day', 'morning', 'lunch', 'evening']);
        $attendanceIds = $all->pluck('attendance_id')->unique()->values();
        $notesByAttendance = Note::query()
            ->whereIn('attendance_id', $attendanceIds->all(), 'and', false)
            ->get(['attendance_id', 'note'])
            ->groupBy('attendance_id');

        $rows = $all->map(function ($row) use ($notesByAttendance) {
            return [
                'attendance_id' => $row->attendance_id,
                'date' => $row->attendance_day,
                'morning' => strtolower((string) $row->morning),
                'lunch' => strtolower((string) $row->lunch),
                'evening' => strtolower((string) $row->evening),
                'notes' => ($notesByAttendance[$row->attendance_id] ?? collect())->pluck('note')->values(),
            ];
        });

        // Aggregate full-day absences per month (AM, Noon, PM all 'absent')
        $monthlyFullDayAbsences = $all
            ->filter(function ($row) {
                return strtolower((string) $row->morning) === 'absent'
                    && strtolower((string) $row->lunch) === 'absent'
                    && strtolower((string) $row->evening) === 'absent';
            })
            ->groupBy(function ($row) {
                return Carbon::parse($row->attendance_day)->format('Y-m');
            })
            ->map(function ($group, $month) {
                $groupArray = is_array($group) ? $group : $group->toArray();
                return [
                    'month' => $month,
                    'fullDayAbsences' => count($groupArray),
                ];
            })
            ->values()
            ->sortBy('month')
            ->values();

        // Use DisciplineService for consistent calculation
        $disciplineService = new DisciplineService();
        $discipline = $disciplineService->calculateDisciplineScore($user);

        return response()->json([
            'discipline' => $discipline,
            'recentAbsences' => $rows,
            'monthlyFullDayAbsences' => $monthlyFullDayAbsences,
        ]);
    }
    public function UserAttendanceChart(User $user)
    {
        $attendances = AttendanceListe::query()
            ->where('user_id', $user->id)
            ->get(['attendance_day', 'morning', 'lunch', 'evening']);
        $monthlyAbsences = $attendances
            ->groupBy(function ($record) {
                // Group by month name, e.g., "October"
                return Carbon::parse($record->attendance_day)->format('F');
            })
            ->map(function ($records) {
                $totalAbsent = 0;

                foreach ($records as $r) {
                    if (strtolower((string) $r->morning) === 'absent') $totalAbsent++;
                    if (strtolower((string) $r->lunch) === 'absent') $totalAbsent++;
                    if (strtolower((string) $r->evening) === 'absent') $totalAbsent++;
                }

                $firstRecord = $records->first();
                return [
                    'month' => $firstRecord ? Carbon::parse($firstRecord->attendance_day)->format('F') : 'Unknown',
                    'absence' => $totalAbsent,
                ];
            })
            ->values(); // reset keys

        return response()->json($monthlyAbsences);
    }

    /**
     * Send newsletter email to users in selected trainings or all users
     */
    public function sendEmail(Request $request)
    {
        $user = $request->user();
        $roles = is_array($user->role) ? $user->role : [$user->role];

        if (! in_array('admin', $roles, true) && ! in_array('coach', $roles, true)) {
            abort(403);
        }

        $isAdmin = in_array('admin', $roles, true);
        $isCoachOnly = ! $isAdmin && in_array('coach', $roles, true);

        $coachTrainingIds = collect();
        if ($isCoachOnly) {
            $coachTrainingIds = Formation::query()
                ->where('user_id', $user->id)
                ->pluck('id');
        }

        $validated = $request->validate([
            'mode' => 'nullable|string|in:training,role,users',
            'training_ids' => 'nullable|array',
            'training_ids.*' => 'integer|exists:formations,id',
            'role_ids' => 'nullable|array',
            'role_ids.*' => 'string',
            'user_ids' => 'nullable|array',
            'user_ids.*' => 'integer|exists:users,id',
            'subject' => 'required|string|max:255',
            'body' => 'nullable|string',
            'body_fr' => 'nullable|string',
            'body_ar' => 'nullable|string',
            'body_en' => 'nullable|string',
        ]);

        // Ensure at least one body field is provided
        if (empty($validated['body']) && empty($validated['body_fr']) && empty($validated['body_ar']) && empty($validated['body_en'])) {
            return response()->json([
                'error' => 'At least one language content (body, body_fr, body_ar, or body_en) is required.'
            ], 400);
        }

        $mode = $validated['mode'] ?? null;
        if (! $mode) {
            // Backward-compatible inference when mode is omitted.
            if (! empty($validated['user_ids'])) {
                $mode = 'users';
            } elseif (array_key_exists('role_ids', $validated) && (is_null($validated['role_ids']) || count($validated['role_ids']) > 0)
                && empty($validated['training_ids'])) {
                $mode = 'role';
            } else {
                $mode = 'training';
            }
        }

        // Coaches can only target their assigned trainings / students (no role broadcast).
        if ($isCoachOnly) {
            if ($mode === 'role') {
                return response()->json([
                    'error' => 'Coaches cannot send newsletters by role.',
                ], 403);
            }

            if ($coachTrainingIds->isEmpty()) {
                return response()->json([
                    'error' => 'You have no trainings assigned.',
                ], 400);
            }
        }

        $users = collect();

        if ($mode === 'training') {
            if (is_null($validated['training_ids'] ?? null)) {
                $query = User::query()->whereNotNull('email');
                if ($isCoachOnly) {
                    $query->whereIn('formation_id', $coachTrainingIds);
                }
                $users = $query->get();
            } elseif (! empty($validated['training_ids'])) {
                $trainingIds = array_values($validated['training_ids']);
                if ($isCoachOnly) {
                    $trainingIds = array_values(array_intersect($trainingIds, $coachTrainingIds->all()));
                    if (empty($trainingIds)) {
                        return response()->json([
                            'error' => 'You can only send to your assigned trainings.',
                        ], 403);
                    }
                }

                $users = User::query()
                    ->whereIn('formation_id', $trainingIds)
                    ->whereNotNull('email')
                    ->get();
            }
        } elseif ($mode === 'role') {
            if (! $isAdmin) {
                return response()->json([
                    'error' => 'Only admins can send newsletters by role.',
                ], 403);
            }

            if (is_null($validated['role_ids'] ?? null)) {
                $users = User::query()->whereNotNull('email')->get();
            } elseif (! empty($validated['role_ids'])) {
                $users = User::query()->whereNotNull('email')->get()->filter(function ($candidate) use ($validated) {
                    $userRoles = is_array($candidate->role) ? $candidate->role : ($candidate->role ? [$candidate->role] : []);

                    return collect($userRoles)->map(fn ($r) => strtolower($r ?? ''))->intersect(
                        collect($validated['role_ids'])->map(fn ($r) => strtolower($r))
                    )->isNotEmpty();
                })->values();
            }
        } elseif ($mode === 'users') {
            if (! empty($validated['user_ids'])) {
                $query = User::query()
                    ->whereIn('id', array_values($validated['user_ids']))
                    ->whereNotNull('email');

                if ($isCoachOnly) {
                    $query->whereIn('formation_id', $coachTrainingIds);
                }

                $users = $query->get();
            }
        }

        $users = $users->unique('id');

        if ($users->isEmpty()) {
            return response()->json([
                'error' => 'No users found to send email to.'
            ], 400);
        }

        // Dispatch jobs for each user
        $totalUsers = $users->count();
        $senderId = $request->user()->id;

        foreach ($users as $recipient) {
            SendNewsletterEmail::dispatch(
                $recipient,
                $validated['subject'],
                $validated['body'] ?? null,
                $validated['body_fr'] ?? null,
                $validated['body_ar'] ?? null,
                $validated['body_en'] ?? null
            );
        }

        NewsletterEmail::create([
            'subject' => $validated['subject'],
            'body' => $validated['body'] ?? null,
            'body_fr' => $validated['body_fr'] ?? null,
            'body_ar' => $validated['body_ar'] ?? null,
            'body_en' => $validated['body_en'] ?? null,
            'recipients_count' => $totalUsers,
            'sent_by' => $senderId,
        ]);

        // Send notification email to admins after jobs are queued
        try {
            $notificationEmails = ['forkanimahdi@gmail.com', 'boujjarr@gmail.com', 'yahyamoussair05@gmail.com'];
            $notificationSubject = 'Newsletter Jobs Queued';
            $notificationBody = "Newsletter emails have been queued for processing.\n\n";
            $notificationBody .= "Subject: {$validated['subject']}\n";
            $notificationBody .= "Total Recipients: {$totalUsers} users\n";
            $notificationBody .= "Queued at: " . now()->format('Y-m-d H:i:s') . "\n\n";
            $notificationBody .= "You can now run the queue worker to process these emails:\n";
            $notificationBody .= "php artisan queue:work";

            foreach ($notificationEmails as $email) {
                Mail::raw($notificationBody, function ($message) use ($email, $notificationSubject) {
                    $message->to($email)
                        ->subject($notificationSubject);
                });
            }
        } catch (\Exception $e) {
            Log::error('Failed to send notification email: '.$e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => "Newsletter emails are being sent to {$totalUsers} user(s) in the background.",
            'total_users' => $totalUsers,
            'queued' => true,
        ]);
    }
}
