<?php

namespace App\Http\Controllers\Organisation;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class OrganisationOnboardingController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        $organization = $user?->organisationAccount;

        if (! $user?->isRecruiter() || ! $organization || ! $user->isOrganisationAccount()) {
            abort(403);
        }

        if ($organization->hasCompletedOnboarding() && ! $user->must_change_password) {
            return redirect()->route('recruiter.dashboard');
        }

        return Inertia::render('organisation/partials/onboardingForm', [
            'organization' => [
                'email' => $organization->email,
                'contact_name' => $organization->contact_name,
                'enterprise_name' => $organization->enterprise_name,
                'sector' => $organization->sector,
                'location' => $organization->location,
                'phone' => $organization->phone,
            ],
            'passwordChangeOnly' => $organization->hasCompletedOnboarding() && $user->must_change_password,
        ]);
    }

    public function validateStep(Request $request): JsonResponse
    {
        $user = $request->user();
        $organization = $user?->organisationAccount;

        if (! $user?->isRecruiter() || ! $organization || ! $user->isOrganisationAccount()) {
            abort(403);
        }

        if ($organization->hasCompletedOnboarding() && ! $user->must_change_password) {
            abort(403);
        }

        if ($organization->hasCompletedOnboarding()) {
            return response()->json(['message' => __('Invalid request.')], 422);
        }

        $step = (int) $request->input('step');

        $rules = match ($step) {
            1 => ['step' => ['required', 'in:1']] + $this->stepOneRules($organization, $user),
            2 => ['step' => ['required', 'in:2']] + $this->stepTwoRules($organization, $user),
            default => ['step' => ['required', 'in:1,2']],
        };

        $request->validate($rules);

        return response()->json(['ok' => true]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $organization = $user?->organisationAccount;

        if (! $user?->isRecruiter() || ! $organization || ! $user->isOrganisationAccount()) {
            abort(403);
        }

        if ($organization->hasCompletedOnboarding() && ! $user->must_change_password) {
            return redirect()->route('recruiter.dashboard');
        }

        if ($organization->hasCompletedOnboarding()) {
            return $this->updatePassword($request, $user);
        }

        $validated = $request->validate(array_merge(
            $this->stepOneRules($organization, $user),
            $this->stepTwoRules($organization, $user),
            [
                'current_password' => ['required', 'current_password:web'],
                'password' => ['required', Password::defaults(), 'confirmed'],
            ]
        ));

        $organization->update([
            'contact_name' => $validated['contact_name'],
            'enterprise_name' => $validated['enterprise_name'],
            'sector' => $validated['sector'],
            'location' => $validated['location'],
            'phone' => $validated['phone'],
            'onboarding_completed_at' => now(),
        ]);

        $user->update([
            'name' => $validated['contact_name'],
            'phone' => $validated['phone'],
            'must_change_password' => false,
        ]);
        $user->forceFill([
            'password' => $validated['password'],
        ])->save();

        return redirect()->route('recruiter.dashboard')->with('success', __('Your organisation profile is complete.'));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function stepOneRules(Organization $organization, User $user): array
    {
        return [
            'contact_name' => [
                'required',
                'string',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail) use ($organization, $user): void {
                    $normalized = mb_strtolower(trim((string) $value));

                    $contactTaken = Organization::query()
                        ->whereKeyNot($organization->getKey())
                        ->whereNotNull('contact_name', 'and')
                        ->whereRaw('LOWER(contact_name) = ?', [$normalized], 'and')
                        ->exists();

                    $nameTaken = User::query()
                        ->whereKeyNot($user->getKey())
                        ->whereRaw('LOWER(name) = ?', [$normalized], 'and')
                        ->exists();

                    if ($contactTaken || $nameTaken) {
                        $fail('This contact name already exists.');
                    }
                },
            ],
            'enterprise_name' => [
                'required',
                'string',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail) use ($organization): void {
                    $exists = Organization::query()
                        ->whereKeyNot($organization->getKey())
                        ->whereNotNull('enterprise_name', 'and')
                        ->whereRaw('LOWER(enterprise_name) = ?', [mb_strtolower(trim((string) $value))], 'and')
                        ->exists();

                    if ($exists) {
                        $fail('This company name already exists.');
                    }
                },
            ],
            'sector' => ['required', 'string', 'max:120'],
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function stepTwoRules(Organization $organization, User $user): array
    {
        return [
            'location' => ['required', 'string', 'max:255'],
            'phone' => [
                'required',
                'string',
                'max:30',
                function (string $attribute, mixed $value, \Closure $fail) use ($organization): void {
                    $normalized = preg_replace('/\s+/', '', trim((string) $value));

                    $phoneTaken = Organization::query()
                        ->whereKeyNot($organization->getKey())
                        ->whereNotNull('phone', 'and')
                        ->whereRaw("REPLACE(phone, ' ', '') = ?", [$normalized], 'and')
                        ->exists();

                    if ($phoneTaken) {
                        $fail('This phone number is already in use by another organisation.');
                    }
                },
            ],
        ];
    }

    private function updatePassword(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password:web'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        $user->forceFill([
            'password' => $validated['password'],
            'must_change_password' => false,
        ])->save();

        return redirect()->route('recruiter.dashboard')->with('success', __('Your password has been updated.'));
    }
}
