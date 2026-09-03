<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserSocialLink;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    private function authenticatedUser(): ?User
    {
        $user = Auth::guard('sanctum')->user() ?? Auth::user();

        return $user instanceof User ? $user : null;
    }

    public function index(Request $request)
    {
        $user = $this->authenticatedUser();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $roles = $user->normalizedRoles();

        // Check if user is admin
        $isAdmin = in_array('admin', array_map('strtolower', $roles)) || in_array('coach', array_map('strtolower', $roles));

        // Base user data (always returned)
        $userData = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            // 'avatar' => $user->image,
            'image' => $user->image,
            'cover' => $user->cover,
            'roles' => $roles,
            'role' => $roles,
            'promo' => $user->promo,
            'status' => $user->status,
            'created_at' => $user->created_at ? (is_string($user->created_at) ? $user->created_at : $user->created_at->toDateTimeString()) : null,
            'updated_at' => $user->updated_at ? (is_string($user->updated_at) ? $user->updated_at : $user->updated_at->toDateTimeString()) : null,
            'access_scan' => $user->access_scan ?? 0,
        ];

        // Sensitive fields - only for admins
        if ($isAdmin) {
            $userData['phone'] = $user->phone ?? null;
            $userData['cin'] = $user->cin ?? null;
            $userData['formation_id'] = $user->formation_id ?? null;
            $userData['account_state'] = $user->account_state ?? 0;
            $userData['state'] = $user->account_state ?? 0;
            $userData['access_cowork'] = $user->access_cowork ?? 0;
            $userData['access_studio'] = $user->access_studio ?? 0;
            $userData['wakatime_api_key'] = $user->wakatime_api_key ? substr($user->wakatime_api_key, 0, 10).'...' : null;
        }

        // Always include last_online for profile display
        $userData['last_online'] = $user->last_online ? (is_string($user->last_online) ? $user->last_online : $user->last_online->format('Y-m-d H:i:s')) : null;

        // Always expose own editable fields
        $userData['phone']  = $user->phone ?? null;
        $userData['status'] = $user->status ?? null;
        $userData['speciality'] = $user->speciality ?? null;
        $userData['about'] = $user->about ?? null;
        $userData['resume'] = $user->resume ?? null;
        $userData['resume_url'] = $user->resumePublicUrl();
        $userData['resume_view_url'] = $user->resumeViewUrl();
        $userData['social_links'] = $user->socialLinks()->ordered()->get(['id', 'title', 'url'])->toArray();

        // Experiences (ordered: latest first)
        $experiences = $user->experiences()
            ->orderBy('start_year', 'desc')
            ->orderBy('start_month', 'desc')
            ->get([
                'experiences.id',
                'experiences.title',
                'experiences.company',
                'experiences.location',
                'experiences.start_month',
                'experiences.start_year',
                'experiences.end_month',
                'experiences.end_year',
            ]);

        $userData['experiences'] = $experiences->toArray();
        $userData['last_experience_location'] = optional($experiences->first())->location;

        // Educations (ordered: latest first)
        $educations = $user->educations()
            ->orderBy('start_year', 'desc')
            ->orderBy('start_month', 'desc')
            ->get([
                'education.id',
                'education.school',
                'education.degree',
                'education.field_of_study',
                'education.start_month',
                'education.start_year',
                'education.end_month',
                'education.end_year',
                'education.description',
            ]);

        $userData['educations'] = $educations->toArray();

        // Add followers and following counts
        $userData['followers_count'] = $user->followers()->count();
        $userData['following_count'] = $user->following()->count();

        // Add posts count
        $userData['posts_count'] = $user->posts()->count();

        // Gamification (mobile More hub / profile)
        $userData['XP'] = (int) ($user->XP ?? 0);
        $userData['xp'] = $userData['XP'];
        $userData['GP'] = (int) ($user->GP ?? 0);
        $userData['gp'] = $userData['GP'];
        $userData['coins'] = $userData['GP'];
        $userData['level'] = (int) ($user->level ?? 0);

        // Own cohort — required for mobile attendance history (not admin-only)
        $userData['formation_ids'] = $user->resolvedFormationIds();
        $userData['formation_id'] = $user->primaryFormationId();

        return response()->json($userData);
    }

    public function show(Request $request, $userId)
    {
        $currentUser = $this->authenticatedUser();

        if (! $currentUser) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $currentUserRolesLower = $currentUser->normalizedRoles();
        $isRecruiter = in_array('recruiter', $currentUserRolesLower, true);
        if ($isRecruiter && (int) $userId !== (int) $currentUser->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $user = User::query()
            ->whereKey($userId)
            ->where('account_state', '=', 0)
            ->first();

        if (! $user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $roles = $user->normalizedRoles();

        $isAdmin = in_array('admin', $currentUserRolesLower, true) || in_array('coach', $currentUserRolesLower, true);
        $isOwnProfile = (int) $userId === (int) $currentUser->id;
        $canViewEmail = $isOwnProfile || $isAdmin || in_array('super_admin', $currentUserRolesLower, true)
            || in_array('moderateur', $currentUserRolesLower, true)
            || in_array('studio_responsable', $currentUserRolesLower, true)
            || in_array('responsable_studio', $currentUserRolesLower, true);

        // Base user data — peer email is owner/staff only (H3).
        $userData = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $canViewEmail ? $user->email : null,
            // 'avatar' => $user->image ? url('storage/'.$user->image) : null,
            'cover' => $user->cover,
            'image' => $user->image,
            'roles' => $roles,
            'role' => $roles,
            'promo' => $user->promo,
            'status' => $user->status,
            'created_at' => $user->created_at ? (is_string($user->created_at) ? $user->created_at : $user->created_at->toDateTimeString()) : null,
            'updated_at' => $user->updated_at ? (is_string($user->updated_at) ? $user->updated_at : $user->updated_at->toDateTimeString()) : null,
        ];

        // Always include last_online
        $userData['last_online'] = $user->last_online ? (is_string($user->last_online) ? $user->last_online : $user->last_online->format('Y-m-d H:i:s')) : null;
        $userData['speciality'] = $user->speciality ?? null;
        $userData['about'] = $user->about ?? null;

        // Experiences (ordered: latest first)
        $experiences = $user->experiences()
            ->orderBy('start_year', 'desc')
            ->orderBy('start_month', 'desc')
            ->get([
                'experiences.id',
                'experiences.title',
                'experiences.company',
                'experiences.location',
                'experiences.start_month',
                'experiences.start_year',
                'experiences.end_month',
                'experiences.end_year',
            ]);

        $userData['experiences'] = $experiences->toArray();
        $userData['last_experience_location'] = optional($experiences->first())->location;

        // Educations (ordered: latest first)
        $educations = $user->educations()
            ->orderBy('start_year', 'desc')
            ->orderBy('start_month', 'desc')
            ->get([
                'education.id',
                'education.school',
                'education.degree',
                'education.field_of_study',
                'education.start_month',
                'education.start_year',
                'education.end_month',
                'education.end_year',
                'education.description',
            ]);

        $userData['educations'] = $educations->toArray();

        // Add followers and following counts
        $userData['followers_count'] = $user->followers()->count();
        $userData['following_count'] = $user->following()->count();

        // Add posts count
        $userData['posts_count'] = $user->posts()->count();

        // Whether the current authenticated viewer follows this user
        $userData['is_following'] = $currentUser
            ? $currentUser->following()->where('followed_id', '=', $user->id)->exists()
            : false;

        // Sensitive fields - only for admins
        if ($isAdmin) {
            $userData['phone'] = $user->phone ?? null;
            $userData['cin'] = $user->cin ?? null;
            $userData['formation_id'] = $user->formation_id ?? null;
            $userData['account_state'] = $user->account_state ?? 0;
            $userData['access_cowork'] = $user->access_cowork ?? 0;
            $userData['access_studio'] = $user->access_studio ?? 0;
            $userData['wakatime_api_key'] = $user->wakatime_api_key ? substr($user->wakatime_api_key, 0, 10).'...' : null;
        }

        return response()->json($userData);
    }

    /**
     * Update the authenticated user's own profile (name, email, phone, status, avatar, resume).
     */
    public function updateProfile(Request $request)
    {
        $user = $this->authenticatedUser();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $data = $request->validate([
            'name'   => 'sometimes|string|max:255',
            'email'  => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone'  => 'sometimes|nullable|string|max:30',
            'status' => 'sometimes|nullable|string|max:255',
            'speciality' => 'sometimes|nullable|string|max:255',
            'image'  => 'sometimes|file|image|mimes:jpeg,jpg,png,webp,gif|max:4096',
            'resume' => 'sometimes|file|mimes:pdf,doc,docx|max:10240',
        ]);

        if ($request->hasFile('image')) {
            $file = $request->file('image');
            if ($file instanceof UploadedFile) {
                $filename = $file->hashName();
                $file->move(public_path('/storage/img/profile'), $filename);
                $data['image'] = $filename;
            }
        }

        if ($request->hasFile('resume')) {
            $resumeFile = $request->file('resume');
            if ($resumeFile instanceof UploadedFile) {
                $data['resume'] = $user->storeResumeFromUpload($resumeFile);
            }
        }

        $allowed = ['name', 'email', 'phone', 'status', 'speciality', 'image', 'resume'];
        $user->update(array_intersect_key($data, array_flip($allowed)));
        $user->refresh();

        return response()->json([
            'message' => 'Profile updated',
            'data'    => [
                'id'     => $user->id,
                'name'   => $user->name,
                'email'  => $user->email,
                'phone'  => $user->phone,
                'status' => $user->status,
                'speciality' => $user->speciality,
                'image'  => $user->image,
                'resume' => $user->resume,
                'resume_url' => $user->resumePublicUrl(),
                'resume_view_url' => $user->resumeViewUrl(),
            ],
        ]);
    }

    /**
     * Update the authenticated user's cover image.
     */
    public function updateCover(Request $request)
    {
        $user = $this->authenticatedUser();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $data = $request->validate([
            'cover' => 'required|file|image|mimes:jpeg,jpg,png,webp,gif|max:6144',
        ]);

        $file = $request->file('cover');

        // Store covers under: storage/app/public/img/cover
        // Public URL (via storage symlink): /storage/img/cover/<filename>
        $disk = 'public';
        $dir = 'img/cover';

        // Best-effort delete old cover (supports either "filename" or "img/cover/filename").
        if (is_string($user->cover) && $user->cover !== '') {
            $old = str_replace('\\', '/', $user->cover);
            if (strpos($old, '/') === false) {
                $old = $dir . '/' . $old;
            }
            Storage::disk($disk)->delete($old);
        }

        $storedPath = $file->store($dir, $disk); // returns "img/cover/<filename>"
        $user->cover = $storedPath;
        $user->save();

        return response()->json([
            'message' => 'Cover updated',
            'data' => [
                'cover' => $user->cover,
            ],
        ]);
    }

    /**
     * List the authenticated user's social links.
     */
    public function listSocialLinks(Request $request)
    {
        $user = $this->authenticatedUser();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $links = $user->socialLinks()->ordered()->get(['id', 'title', 'url']);

        return response()->json(['data' => $links]);
    }

    /**
     * Add a social link for the authenticated user.
     */
    public function addSocialLink(Request $request)
    {
        $user = $this->authenticatedUser();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $data = $request->validate([
            'title' => 'required|string|max:80',
            'url'   => UserSocialLink::URL_RULES,
        ]);

        $link = UserSocialLink::create([
            'user_id'    => $user->id,
            'title'      => $data['title'],
            'url'        => $data['url'],
            'sort_order' => (int) UserSocialLink::query()->where('user_id', '=', $user->id)->max('sort_order') + 1,
        ]);

        return response()->json(['data' => $link], 201);
    }

    /**
     * Delete a social link belonging to the authenticated user.
     */
    public function deleteSocialLink(Request $request, int $id)
    {
        $user = $this->authenticatedUser();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $link = UserSocialLink::query()
            ->whereKey($id)
            ->where('user_id', '=', $user->id)
            ->first();

        if (! $link) {
            return response()->json(['message' => 'Not found'], 404);
        }

        UserSocialLink::destroy($link->id);

        return response()->json(['message' => 'Deleted']);
    }

    /**
     * Return the list of users who follow the given user.
     * Each entry includes `is_following` — whether the authenticated viewer already follows that user.
     */
    public function listFollowers(Request $request, int $userId)
    {
        $currentUser = $this->authenticatedUser();

        if (! $currentUser) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $user = User::findOrFail($userId);

        // Pre-fetch the IDs the current viewer already follows to avoid N+1 queries.
        $viewerFollowingIds = $currentUser->following()->pluck('users.id')->toArray();

        $followers = $user->followers()
            ->select('users.id', 'users.name', 'users.image', 'users.promo', 'users.status')
            ->get()
            ->map(fn ($u) => [
                'id'           => $u->id,
                'name'         => $u->name,
                'image'        => $u->image,
                'promo'        => $u->promo,
                'status'       => $u->status,
                'is_following' => in_array($u->id, $viewerFollowingIds),
            ]);

        return response()->json(['data' => $followers]);
    }

    /**
     * Return the list of users the given user is following.
     * Each entry includes `is_following` — whether the authenticated viewer already follows that user.
     */
    public function listFollowing(Request $request, int $userId)
    {
        $currentUser = $this->authenticatedUser();

        if (! $currentUser) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $user = User::findOrFail($userId);

        $viewerFollowingIds = $currentUser->following()->pluck('users.id')->toArray();

        $following = $user->following()
            ->select('users.id', 'users.name', 'users.image', 'users.promo', 'users.status')
            ->get()
            ->map(fn ($u) => [
                'id'           => $u->id,
                'name'         => $u->name,
                'image'        => $u->image,
                'promo'        => $u->promo,
                'status'       => $u->status,
                'is_following' => in_array($u->id, $viewerFollowingIds),
            ]);

        return response()->json(['data' => $following]);
    }

    /**
     * Follow a user (mobile). If already followed, this is a no-op.
     */
    public function follow(Request $request, int $userId)
    {
        $currentUser = $this->authenticatedUser();

        if (! $currentUser) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        if ((int) $currentUser->id === (int) $userId) {
            return response()->json(['message' => 'You cannot follow yourself'], 400);
        }

        $target = User::findOrFail($userId);

        $already = $currentUser->following()->where('followed_id', '=', $target->id)->exists();

        if ($already) {
            $currentUser->following()->detach($target->id);
        } else {
            $currentUser->following()->syncWithoutDetaching([$target->id]);
        }

        return response()->json([
            'followed' => ! $already,
            'user_id'  => (int) $target->id,
        ]);
    }
}
