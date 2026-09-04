<?php

namespace App\Http\Controllers\Organisation;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrganisationCompanyController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user();
        if (! $user?->isOrganisationAccount()) {
            abort(403);
        }

        $organization = $user->organisationAccount;
        if (! $organization) {
            abort(403);
        }

        return Inertia::render('organisation/company/index', [
            'organization' => [
                'id' => $organization->id,
                'email' => $organization->email,
                'enterprise_name' => $organization->enterprise_name,
                'contact_name' => $organization->contact_name,
                'sector' => $organization->sector,
                'location' => $organization->location,
                'phone' => $organization->phone,
                'onboarding_completed' => $organization->hasCompletedOnboarding(),
                'onboarding_completed_at' => $organization->onboarding_completed_at?->toIso8601String(),
            ],
        ]);
    }
}
