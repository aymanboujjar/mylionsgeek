<?php

namespace App\Http\Controllers\Organisation;

use App\Http\Controllers\Controller;
use App\Mail\EmployerInvitedMail;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class OrganisationMemberController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        if (! $user?->canManageOrganisationMembers()) {
            abort(403);
        }

        $organization = $user->organisationAccount;
        if (! $organization) {
            abort(403);
        }

        $employers = $organization->employers()
            ->orderByDesc('organization_user.created_at')
            ->get()
            ->map(fn (User $employer) => [
                'id' => $employer->id,
                'name' => $employer->name,
                'email' => $employer->email,
                'member_role' => $employer->pivot->member_role,
                'created_at' => $employer->pivot->created_at?->toIso8601String(),
                'last_online' => $employer->last_online,
            ]);

        return Inertia::render('organisation/members/index', [
            'organization' => [
                'id' => $organization->id,
                'display_name' => $organization->displayName(),
            ],
            'employers' => $employers,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user?->canManageOrganisationMembers()) {
            abort(403);
        }

        $organization = $user->organisationAccount;
        if (! $organization) {
            abort(403);
        }

        $validated = $request->validate([
            'email' => 'required|email|max:255|unique:users,email|unique:organizations,email',
            'name' => 'nullable|string|max:255',
        ]);

        $plainPassword = Str::password(14);
        $email = strtolower(trim($validated['email']));
        $displayName = filled($validated['name'] ?? null)
            ? trim($validated['name'])
            : Str::before($email, '@');

        $employer = DB::transaction(function () use ($user, $organization, $email, $displayName, $plainPassword): User {
            $lastUser = User::query()->orderByDesc('id')->first();
            $nextId = $lastUser ? ((int) $lastUser->id) + 1 : 1;

            $employer = new User();
            $employer->forceFill([
                'id' => $nextId,
                'name' => $displayName,
                'email' => $email,
                'password' => $plainPassword,
                'must_change_password' => true,
                'phone' => null,
                'image' => 'pdp.png',
                'status' => 'Working',
                'cin' => null,
                'formation_id' => null,
                'account_state' => $organization->account_state,
                'access_studio' => 0,
                'access_cowork' => 0,
                'role' => ['recruiter'],
                'email_verified_at' => now(),
                'activation_token' => null,
            ])->save();

            $organization->employers()->attach($employer->id, [
                'member_role' => 'employer',
                'invited_by' => $user->id,
            ]);

            return $employer;
        });

        try {
            Mail::to($employer->email)->send(new EmployerInvitedMail($employer, $organization, $plainPassword));
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()->back()->with(
                'warning',
                'Employer invited, but the invitation email could not be sent. Check your mail configuration.'
            );
        }

        return redirect()->back()->with('success', 'Employer invited. Login details were sent by email.');
    }
}
