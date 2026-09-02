<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use App\Mail\ForgotPasswordLinkMail;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'must_change_password',
        'phone',
        'cin',
        'status',
        'formation_id',
        'image',
        'resume',
        'cover', // add cover here
        'about', // short bio
        'speciality',
        'socials', // social links JSON
        'promo',
        'remember_token',
        'email_verified_at',
        'created_at',
        'updated_at',
        'last_online',
        'invite_source',
        'expo_push_token', // Expo push notification token
        // 'xp'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'activation_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_online' => 'datetime',
            'activation_token_expires_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'role' => 'array',
            'socials' => 'array',
        ];
    }

    public const ACTIVATION_TTL_HOURS = 24;

    /**
     * Issue a new activation credential. Returns the plaintext token for the
     * signed email URL only — the database stores a hash.
     */
    public function issueActivationToken(): string
    {
        $plain = bin2hex(random_bytes(32));

        $this->forceFill([
            'activation_token' => hash('sha256', $plain),
            'activation_token_expires_at' => now()->addHours(self::ACTIVATION_TTL_HOURS),
        ])->save();

        return $plain;
    }

    public function hasPendingActivation(): bool
    {
        return filled($this->getRawOriginal('activation_token') ?? $this->activation_token);
    }

    public function consumeActivationToken(): void
    {
        $this->forceFill([
            'activation_token' => null,
            'activation_token_expires_at' => null,
        ])->save();
    }

    public static function findByActivationToken(string $plain): ?self
    {
        $plain = trim($plain);
        if ($plain === '') {
            return null;
        }

        $hashed = hash('sha256', $plain);

        $user = static::query()
            ->where('activation_token', $hashed)
            ->orWhere('activation_token', $plain)
            ->first();

        if (! $user) {
            return null;
        }

        $expiresAt = $user->activation_token_expires_at;
        if ($expiresAt !== null && $expiresAt->isPast()) {
            return null;
        }

        return $user;
    }

    /**
     * Normalize role values from DB/casts (JSON arrays, comma lists, quoted strings).
     *
     * @return list<string>
     */
    public function normalizedRoles(): array
    {
        $cast = $this->role;
        if (is_array($cast) && $cast !== []) {
            return self::normalizeRolesValue($cast);
        }

        $raw = $this->getRawOriginal('role');
        if ($raw === null || $raw === '') {
            return [];
        }

        return self::normalizeRolesValue($raw);
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    public static function normalizeRolesValue($value): array
    {
        if (is_array($value)) {
            $list = $value;
        } elseif (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $list = $decoded;
            } else {
                $list = array_map('trim', explode(',', $value));
            }
        } else {
            return [];
        }

        $normalized = [];
        foreach ($list as $role) {
            if ($role === null || $role === '') {
                continue;
            }
            $role = strtolower(trim((string) $role));
            $role = trim($role, "'\"");
            if ($role !== '') {
                $normalized[] = $role;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * Matches mobile userHasAdminRole: the `admin` role only.
     */
    public function isEventsAdmin(): bool
    {
        return in_array('admin', $this->normalizedRoles(), true);
    }

    /**
     * Matches mobile userCanAccessScan: admin role or access_scan grant.
     * Reads the authenticated user record only — never request body flags.
     */
    public function canAccessEventsScan(): bool
    {
        if ($this->isEventsAdmin()) {
            return true;
        }

        $flag = $this->access_scan;

        return $flag === 1 || $flag === true || $flag === '1';
    }

    public const RESUME_DISK = 'public';

    public const RESUME_DIRECTORY = 'resumes';

    /** Relative path on the public disk, e.g. resumes/abc.pdf */
    public function resumeStoragePath(): ?string
    {
        $name = $this->resume;
        if (! is_string($name) || $name === '') {
            return null;
        }

        $basename = basename($name);

        return $basename !== '' ? self::RESUME_DIRECTORY . '/' . $basename : null;
    }

    public function resumePublicUrl(): ?string
    {
        $relative = $this->resumeStoragePath();
        if (! $relative) {
            return null;
        }

        if (Storage::disk(self::RESUME_DISK)->exists($relative)) {
            return asset('storage/' . ltrim($relative, '/'));
        }

        $basename = basename($relative);
        foreach (['storage/resumes', 'storage/resume'] as $legacyDir) {
            $legacy = public_path($legacyDir . '/' . $basename);
            if (is_file($legacy)) {
                return asset($legacyDir . '/' . $basename);
            }
        }

        return null;
    }

    public function resolveResumeAbsolutePath(): ?string
    {
        $relative = $this->resumeStoragePath();
        if ($relative && Storage::disk(self::RESUME_DISK)->exists($relative)) {
            return Storage::disk(self::RESUME_DISK)->path($relative);
        }

        $basename = basename((string) $this->resume);
        if ($basename === '') {
            return null;
        }

        foreach (['storage/resumes', 'storage/resume'] as $legacyDir) {
            $legacy = public_path($legacyDir . '/' . $basename);
            if (is_file($legacy)) {
                return $legacy;
            }
        }

        return null;
    }

    public function resumeViewUrl(): ?string
    {
        if (! $this->resume) {
            return null;
        }

        return route('users.resume.view', $this);
    }

    public function readStoredResumeContents(): ?string
    {
        $relative = $this->resumeStoragePath();
        if (! $relative) {
            return null;
        }

        $disk = Storage::disk(self::RESUME_DISK);
        if ($disk->exists($relative)) {
            return $disk->get($relative);
        }

        $basename = basename($relative);
        foreach (['storage/resumes', 'storage/resume'] as $legacyDir) {
            $legacy = public_path($legacyDir . '/' . $basename);
            if (is_file($legacy) && is_readable($legacy)) {
                $contents = file_get_contents($legacy);

                return $contents !== false ? $contents : null;
            }
        }

        return null;
    }

    public function deleteStoredResume(): void
    {
        $relative = $this->resumeStoragePath();
        if ($relative) {
            Storage::disk(self::RESUME_DISK)->delete($relative);
        }

        $basename = basename((string) $this->resume);
        if ($basename === '') {
            return;
        }

        foreach (['storage/resumes', 'storage/resume'] as $legacyDir) {
            $legacy = public_path($legacyDir . '/' . $basename);
            if (is_file($legacy)) {
                @unlink($legacy);
            }
        }
    }

    public function storeResumeFromUpload(UploadedFile $file): string
    {
        $this->deleteStoredResume();
        $filename = $file->hashName();
        $file->storeAs(self::RESUME_DIRECTORY, $filename, self::RESUME_DISK);

        return $filename;
    }

    public function access(): HasOne
    {
        return $this->hasOne(Access::class);
    }
    public function formation()
    {
        return $this->belongsTo(Formation::class, 'formation_id');
    }

    /**
     * All formation IDs this user belongs to (column, pivot, or promo match).
     */
    public function resolvedFormationIds(): array
    {
        $ids = [];

        if (! empty($this->formation_id)) {
            $ids[] = (int) $this->formation_id;
        }

        if (Schema::hasTable('formation_user')) {
            $pivotIds = DB::table('formation_user')
                ->where('user_id', $this->id)
                ->pluck('formation_id')
                ->map(fn($id) => (int) $id)
                ->all();
            $ids = array_merge($ids, $pivotIds);
        }

        if ($this->promo !== null && $this->promo !== '') {
            $promo = $this->promo;
            $byPromo = Formation::query()
                ->where(function ($q) use ($promo) {
                    $q->where('promo', $promo)
                        ->orWhere('promo', (string) $promo);
                })
                ->pluck('id')
                ->map(fn($id) => (int) $id)
                ->all();
            $ids = array_merge($ids, $byPromo);
        }

        return array_values(array_unique(array_filter($ids, fn($id) => $id > 0)));
    }

    public function primaryFormationId(): ?int
    {
        $ids = $this->resolvedFormationIds();

        return $ids[0] ?? null;
    }

    public function isEnrolledInFormation(int $formationId): bool
    {
        return in_array($formationId, $this->resolvedFormationIds(), true);
    }

    /**
     * User projects
     */
    // public function projects()
    // {
    //     return $this->hasMany(UserProject::class, 'user_id');
    // }

    public function studentProjects(): HasMany
    {
        return $this->hasMany(StudentProject::class, 'user_id');
    }

    /**
     * Projects this user approved
     */
    public function approvedProjects()
    {
        return $this->hasMany(StudentProject::class, 'approved_by');
    }


    /**
     * Get Geekos created by this user.
     */
    public function createdGeekos()
    {
        return $this->hasMany(Geeko::class, 'created_by');
    }

    /**
     * Get Geeko sessions started by this user.
     */
    public function startedSessions()
    {
        return $this->hasMany(GeekoSession::class, 'started_by');
    }

    /**
     * Get Geeko participations for this user.
     */
    public function geekoParticipations()
    {
        return $this->hasMany(GeekoParticipant::class, 'user_id');
    }
    public function scopeActive($query)
    {
        return $query->where('account_state', 0);
    }

    /**
     * User has many reservations as creator
     */
    public function reservations()
    {
        return $this->hasMany(Reservation::class, 'user_id');
    }

    /**
     * User can be in many reservation teams (Many-to-Many)
     */
    public function reservationTeams()
    {
        return $this->belongsToMany(Reservation::class, 'reservation_teams', 'user_id', 'reservation_id')->withTimestamps();
    }
    public function badges()
    {
        return $this->belongsToMany(Badge::class)->withTimestamps();
    }
    public function posts()
    {
        return $this->hasMany(Post::class);
    }
    public function likes()
    {
        return $this->hasMany(Like::class);
    }
    public function comments()
    {
        return $this->hasMany(Comment::class);
    }

    public function savedPosts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'post_saves', 'user_id', 'post_id')->withTimestamps();
    }

    public function repostedPosts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'reposts_posts', 'user_id', 'post_id')
            ->withPivot(['description'])
            ->withTimestamps();
    }

    /**
     * Get conversations where this user is user_one
     */
    public function conversationsAsUserOne()
    {
        return $this->hasMany(Conversation::class, 'user_one_id');
    }

    /**
     * Get conversations where this user is user_two
     */
    public function conversationsAsUserTwo()
    {
        return $this->hasMany(Conversation::class, 'user_two_id');
    }

    /**
     * Get all conversations for this user
     */
    public function conversations(): \Illuminate\Database\Eloquent\Builder
    {
        // Use explicit operator/value signature to satisfy analyzers and avoid ambiguity.
        return Conversation::query()
            ->where('user_one_id', '=', $this->id)
            ->orWhere('user_two_id', '=', $this->id);
    }

    /**
     * Get all messages sent by this user
     */
    public function sentMessages()
    {
        return $this->hasMany(Message::class, 'sender_id');
    }

    /**
     * Send the password reset notification using our custom mailable and layout.
     */
    public function sendPasswordResetNotification($token)
    {
        $resetUrl = url(route('password.reset', ['token' => $token, 'email' => $this->email], false));

        Mail::to($this->email)->send(new ForgotPasswordLinkMail($this, $resetUrl));
    }
    //! Followers relationship
    public function followers()
    {
        return $this->belongsToMany(
            User::class,
            'followers',
            'followed_id',
            'follower_id'
        )->withTimestamps();
    }

    // People I follow
    public function following()
    {
        return $this->belongsToMany(
            User::class,
            'followers',
            'follower_id',
            'followed_id'
        )->withTimestamps();
    }
    public function experiences()
    {
        return $this->belongsToMany(Experience::class)->withTimestamps();
    }
    public function educations()
    {
        return $this->belongsToMany(Education::class)->withTimestamps();
    }

    public function socialLinks()
    {
        return $this->hasMany(UserSocialLink::class);
    }

    /** Organisation this user logs in as (the company account). */
    public function organisationAccount(): HasOne
    {
        return $this->hasOne(Organization::class, 'account_user_id');
    }

    /** Organisations this user belongs to as an invited employer. */
    public function employerOrganizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_user')
            ->withPivot(['member_role', 'invited_by'])
            ->withTimestamps();
    }

    public function isRecruiter(): bool
    {
        $roles = is_array($this->role) ? $this->role : [$this->role];

        return in_array('recruiter', $roles, true);
    }

    public function isOrganisationAccount(): bool
    {
        if ($this->relationLoaded('organisationAccount')) {
            return $this->organisationAccount !== null;
        }

        return Organization::query()->where('account_user_id', '=', $this->id)->exists();
    }

    public function organizationForRecruiting(): ?Organization
    {
        if ($this->relationLoaded('organisationAccount') && $this->organisationAccount) {
            return $this->organisationAccount;
        }

        $asAccount = Organization::query()->where('account_user_id', '=', $this->id)->first();
        if ($asAccount) {
            return $asAccount;
        }

        if ($this->relationLoaded('employerOrganizations')) {
            return $this->employerOrganizations->first();
        }

        return $this->employerOrganizations()->first();
    }

    public function organizationIdForRecruiting(): ?int
    {
        return $this->organizationForRecruiting()?->id;
    }

    public function canCreateJobsForOrganisation(): bool
    {
        if (! $this->isRecruiter()) {
            return false;
        }

        if ($this->isOrganisationAccount()) {
            return true;
        }

        $organizationId = $this->organizationIdForRecruiting();
        if (! $organizationId) {
            return false;
        }

        return $this->employerOrganizations()
            ->where('organizations.id', '=', $organizationId, 'and')
            ->whereIn('organization_user.member_role', ['employer', 'admin'], 'and', false)
            ->exists();
    }

    public function canManageOrganisationMembers(): bool
    {
        return $this->isOrganisationAccount();
    }

    /**
     * Shared Inertia context for recruiter UI (org account vs team member).
     *
     * @return array{
     *   organization_id: int,
     *   organization_name: string,
     *   membership_type: 'organisation_account'|'employer',
     *   membership_label: string,
     *   member_role: string,
     *   can_manage_team: bool,
     *   can_create_jobs: bool
     * }|null
     */
    public function recruitingContext(): ?array
    {
        if (! $this->isRecruiter()) {
            return null;
        }

        $organization = $this->organizationForRecruiting();
        if (! $organization) {
            return null;
        }

        $isOrgAccount = $this->isOrganisationAccount();
        $memberRole = 'owner';

        if (! $isOrgAccount) {
            if ($this->relationLoaded('employerOrganizations')) {
                $pivotOrg = $this->employerOrganizations->firstWhere('id', $organization->id);
            } else {
                $pivotOrg = $this->employerOrganizations()
                    ->where('organizations.id', $organization->id)
                    ->first();
            }
            $memberRole = $pivotOrg?->pivot?->member_role ?? 'employer';
        }

        $membershipType = $isOrgAccount ? 'organisation_account' : 'employer';
        $membershipLabel = $isOrgAccount ? __('Organisation owner') : __('Team member');

        return [
            'organization_id' => (int) $organization->id,
            'organization_name' => $organization->displayName(),
            'membership_type' => $membershipType,
            'membership_label' => $membershipLabel,
            'member_role' => $memberRole,
            'can_manage_team' => $this->canManageOrganisationMembers(),
            'can_create_jobs' => $this->canCreateJobsForOrganisation(),
        ];
    }
    public function announcements()
    {
        return $this->hasMany(Announcement::class, 'created_by');
    }
}
