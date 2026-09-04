<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\OrganisationInvitedMail;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class OrganisationController extends Controller
{
    public function index(): Response
    {
        $organisations = Organization::query()
            ->with(['accountUser:id,name,email,image,last_online'])
            ->withCount('employers')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Organization $org) => [
                'id' => $org->id,
                'email' => $org->email,
                'enterprise_name' => $org->enterprise_name,
                'contact_name' => $org->contact_name,
                'sector' => $org->sector,
                'location' => $org->location,
                'phone' => $org->phone,
                'account_state' => (int) $org->account_state,
                'onboarding_completed' => $org->hasCompletedOnboarding(),
                'onboarding_completed_at' => $org->onboarding_completed_at?->toIso8601String(),
                'created_at' => $org->created_at?->toIso8601String(),
                'employers_count' => $org->employers_count,
                'account' => $org->accountUser ? [
                    'id' => $org->accountUser->id,
                    'name' => $org->accountUser->name,
                    'email' => $org->accountUser->email,
                    'image' => $org->accountUser->image,
                    'last_online' => $org->accountUser->last_online,
                ] : null,
            ]);

        return Inertia::render('admin/organisations/index', [
            'organisations' => $organisations,
        ]);
    }

    public function show(Organization $organization): Response
    {
        $organization->load([
            'accountUser:id,name,email,image,last_online,account_state,status',
            'employers' => fn ($query) => $query->orderByDesc('organization_user.created_at'),
        ]);

        $teamMembers = collect();

        if ($organization->accountUser) {
            $account = $organization->accountUser;
            $teamMembers->push([
                'id' => $account->id,
                'name' => $account->name,
                'email' => $account->email,
                'image' => $account->image,
                'last_online' => $account->last_online,
                'status' => $account->status,
                'account_state' => (int) $account->account_state,
                'member_role' => 'owner',
                'member_label' => 'Organisation owner',
                'joined_at' => $organization->created_at?->toIso8601String(),
            ]);
        }

        foreach ($organization->employers as $employer) {
            $teamMembers->push([
                'id' => $employer->id,
                'name' => $employer->name,
                'email' => $employer->email,
                'image' => $employer->image,
                'last_online' => $employer->last_online,
                'status' => $employer->status,
                'account_state' => (int) $employer->account_state,
                'member_role' => $employer->pivot->member_role,
                'member_label' => ucfirst((string) ($employer->pivot->member_role ?? 'employer')),
                'joined_at' => $employer->pivot->created_at?->toIso8601String(),
            ]);
        }

        return Inertia::render('admin/organisations/[id]', [
            'organization' => [
                'id' => $organization->id,
                'email' => $organization->email,
                'enterprise_name' => $organization->enterprise_name,
                'contact_name' => $organization->contact_name,
                'sector' => $organization->sector,
                'location' => $organization->location,
                'phone' => $organization->phone,
                'account_state' => (int) $organization->account_state,
                'onboarding_completed' => $organization->hasCompletedOnboarding(),
                'display_name' => $organization->displayName(),
                'employers_count' => $organization->employers->count(),
            ],
            'teamMembers' => $teamMembers->values()->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => 'required|email|max:255|unique:organizations,email|unique:users,email',
        ]);

        $plainPassword = Str::password(14);
        $email = strtolower(trim($validated['email']));
        $displayName = Str::before($email, '@');

        $user = DB::transaction(function () use ($request, $email, $displayName, $plainPassword): User {
            $organization = Organization::create([
                'email' => $email,
                'invited_by' => $request->user()?->id,
                'account_state' => 0,
            ]);

            $lastUser = User::query()->orderByDesc('id')->first();
            $nextId = $lastUser ? ((int) $lastUser->id) + 1 : 1;

            $accountUser = new User();
            $accountUser->forceFill([
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
                'account_state' => 0,
                'access_studio' => 0,
                'access_cowork' => 0,
                'role' => ['recruiter'],
                'email_verified_at' => now(),
                'activation_token' => null,
            ])->save();

            $organization->update(['account_user_id' => $accountUser->id]);

            return $accountUser;
        });

        try {
            Mail::to($user->email)->send(new OrganisationInvitedMail($user, $plainPassword));
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()->back()->with(
                'warning',
                'Organisation invited, but the invitation email could not be sent. Check your mail configuration.'
            );
        }

        return redirect()->back()->with('success', 'Organisation account created. Login details were sent by email.');
    }

    public function updateAccountState(Request $request, Organization $organization): RedirectResponse
    {
        $validated = $request->validate([
            'account_state' => 'required|integer|in:0,1',
        ]);

        $organization->update(['account_state' => $validated['account_state']]);

        $organization->load('employers');

        $userIds = collect([$organization->account_user_id])
            ->merge($organization->employers->pluck('id'))
            ->filter()
            ->unique()
            ->values();

        if ($userIds->isNotEmpty()) {
            User::query()->whereIn('id', $userIds)->update(['account_state' => $validated['account_state']]);
        }

        return redirect()->back()->with('success', 'Organisation account status updated.');
    }
}
