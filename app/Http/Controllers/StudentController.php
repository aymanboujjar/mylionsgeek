<?php

namespace App\Http\Controllers;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudentController extends Controller
{
    private function authorizeFeedAccess(): void
    {
        $user = Auth::user();
        $roles = is_array($user->role) ? $user->role : array_filter([$user->role]);

        if (! in_array('recruiter', $roles, true)) {
            return;
        }

        $rolesExcludingRecruiter = array_values(array_filter($roles, fn ($role) => $role !== 'recruiter'));

        if ($rolesExcludingRecruiter === []) {
            abort(403);
        }
    }

    public function index(Request $request)
    {
        $this->authorizeFeedAccess();
        $userController = new UsersController;
        $cursor = $request->query('cursor');
        $paginated = $userController->getPostsPaginated(
            perPage: 10,
            cursor: is_string($cursor) && $cursor !== '' ? $cursor : null,
        );
        $userId = Auth::user()->id;
        $user = $this->getUserInfo($userId);

        return Inertia::render('students/user/index', [
            'feedPosts' => Inertia::merge($paginated['posts'])->matchOn('id'),
            'feedNextCursor' => $paginated['next_cursor'],
            'feedHasMore' => $paginated['has_more'],
            'user' => $user,
        ]);
    }

    public function userProfile($id)
    {
        $user = $this->getUserInfo($id);
        $usersController = new UsersController;
        $profilePosts = $usersController->getPostsForProfileUser((int) $id, 1);

        return Inertia::render('students/user/partials/StudentProfile', [
            'user' => $user,
            'profilePostsPreview' => $profilePosts['posts']->values()->all(),
            'profilePostsTotal' => $profilePosts['total'],
        ]);
    }

    public function userPosts($id)
    {
        $user = $this->getUserInfo($id);
        $usersController = new UsersController;
        $profilePosts = $usersController->getPostsForProfileUser((int) $id, null);

        return Inertia::render('students/user/UserPosts', [
            'user' => $user,
            'posts' => $profilePosts['posts']->values()->all(),
            'postsTotal' => $profilePosts['total'],
        ]);
    }

    public function getUserInfo($id)
    {
        $user = User::find($id);
        $userExperience = User::with(['experiences' => function ($query) {
            $query->orderBy('start_year', 'desc')->orderBy('start_month', 'desc');
        }])->findOrFail($id);
        $userEducation = User::with([
            'educations' => function ($query) {
                $query->orderBy('start_year', 'desc')->orderBy('start_month', 'desc');
            },
        ])->findOrFail($id);
        $userSocialLinks = User::with(['socialLinks' => function ($query) {
            $query->ordered();
        }])->findOrFail($id);
        $isFollowing = Auth::user()
            ->following()
            ->where('followed_id', $id)
            ->exists();
        // dd($isFollowing);
        $followers = User::find($id)
            ->followers()
            ->select('users.id', 'users.name', 'users.image')
            ->get();
        $following = User::find($id)
            ->following()
            ->select('users.id', 'users.name', 'users.image')
            ->get();

        $isOwner = Auth::id() === (int) $id;
        $actor = Auth::user();
        $actorRoles = is_array($actor?->role) ? $actor->role : array_filter([(string) ($actor?->role ?? '')]);
        $actorRolesLower = array_map('strtolower', array_map('strval', $actorRoles));
        $canViewContact = $isOwner || (bool) array_intersect($actorRolesLower, [
            'admin',
            'super_admin',
            'moderateur',
            'coach',
            'studio_responsable',
            'responsable_studio',
            'recruiter',
        ]);
        $hasResume = (bool) $user->resumeViewUrl();

        return [
            'user' => [
                'id' => $user->id,
                'email' => $canViewContact ? $user->email : null,
                'image' => $user->image,
                'online' => $user->last_online,
                'Gp' => $user->GP,
                'Xp' => $user->XP,
                'about' => $user->about,
                'speciality' => $user->speciality,
                'socials' => $user->socials,
                'level' => $user->level,
                'promo' => $user->promo,
                'cover' => $user->cover,
                'name' => $user->name,
                'last_online' => $user->last_online,
                'status' => $user->status,
                'field' => $user->field,
                'phone' => $isOwner ? $user->phone : null,
                'resume' => $canViewContact ? $user->resume : null,
                // Never expose the public /storage URL; use the gated stream route.
                'resume_url' => null,
                'resume_view_url' => ($canViewContact && $hasResume) ? $user->resumeViewUrl() : null,
                'created_at' => $user->created_at->format('Y-m-d'),
                'formation' => $user->formation_id != null ? ($user->formation?->name ?? '') : '',
                'formation_id' => $user->formation_id,
                'cin' => $isOwner ? $user->cin : null,
                'access_studio' => $user->access_studio,
                'access_cowork' => $user->access_cowork,
                'role' => $user->role,
                'followers' => $followers,
                'following' => $following,
                'isFollowing' => $isFollowing,
                'experiences' => $userExperience->experiences,
                'educations' => $userEducation->educations,
                'social_links' => $userSocialLinks->socialLinks,
                'certified_at' => $user->certified_at
                    ? Carbon::parse($user->certified_at)->format('Y-m-d')
                    : null,
                'certificate_pdf_path' => $isOwner ? $user->certificate_pdf_path : null,
                'certificate_pdf_url' => $isOwner ? $this->resolveCertificatePdfUrl($user) : null,
            ],
        ];
    }

    /**
     * Public URL for a stored certificate PDF, or null if path is unset or file missing.
     */
    private function resolveCertificatePdfUrl(User $user): ?string
    {
        $pdfPath = $user->certificate_pdf_path;

        if (! $pdfPath || ! Storage::disk('public')->exists($pdfPath)) {
            return null;
        }

        return url('/storage/' . ltrim($pdfPath, '/'));
    }

    /**
     * Download the authenticated user's stored certificate PDF (profile owner only).
     */
    public function downloadCertificate(Request $request): StreamedResponse
    {
        $user = $request->user();

        $pdfPath = $user->certificate_pdf_path;

        if (! $pdfPath || ! Storage::disk('public')->exists($pdfPath)) {
            abort(404, 'Certificate file not found.');
        }

        $safeName = Str::slug((string) $user->name) ?: 'certificate';

        return Storage::disk('public')->download($pdfPath, "certificate_{$safeName}.pdf");
    }

    public function changeProfileImage(Request $request, $id)
    {
        $user = User::find($id);
        if (Auth::user()->id == $user->id) {
            // code...
            $request->validate([
                'image' => 'required|image|mimes:jpeg,jpg,png,webp,gif|max:2048',
            ]);
            if ($request->hasFile('image') && $request->file('image')->isValid()) {
                $file = $request->file('image');
                $path = $file->store('img/profile', 'public');
                $request->image = basename($path);
                $user->update([
                    'image' => $request->image,
                ]);

                return redirect()->back()->with('success', 'image changed successfully');
            }

            return redirect()->back()->with('error', 'There was an error changing the image.');
        }
    }

    public function changeCover(Request $request, $id)
    {
        $user = User::find($id);
        if (Auth::user()->id == $user->id) {
            $request->validate([
                'cover' => 'required|image|mimes:jpeg,jpg,png,webp,gif|max:2048',
            ]);
            if ($request->hasFile('cover') && $request->file('cover')->isValid()) {
                $path = $request->file('cover')->store('img/cover', 'public');
                $user->update([
                    'cover' => $path,
                ]);

                return redirect()->back()->with('success', 'Cover changed successfully');
            }

            return redirect()->back()->with('error', 'There was an error changing the cover.');
        }
    }

    public function updateAbout(Request $request, $id)
    {
        if (Auth::id() !== (int) $id) {
            abort(403);
        }

        $request->validate([
            'about' => 'string|max:500|min:100|required',
        ]);

        $user = User::findOrFail($id);

        $user->update([
            'about' => $request->about,
        ]);

        return back()->with('success', 'About updated successfully');
    }
}
