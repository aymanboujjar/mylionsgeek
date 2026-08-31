<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// The export filename embeds a timestamp, so the clock is frozen to keep it predictable.
function expectedExportFilename(): string
{
    return 'students_export_' . now()->format('Y_m_d_H_i_s') . '.xlsx';
}

function exportingUserWithRoles(array $roles): User
{
    /** @var User $user */
    $user = User::factory()->create(['role' => $roles]);

    return $user;
}

beforeEach(function () {
    Carbon::setTestNow('2026-08-31 12:00:00');
    Excel::fake();
});

afterEach(function () {
    Carbon::setTestNow();
});

test('has_handicap is cast to a boolean and stays null when never answered', function () {
    $declaredHandicap = User::factory()->create(['has_handicap' => 1]);
    $declaredNoHandicap = User::factory()->create(['has_handicap' => 0]);
    $neverAnswered = User::factory()->create();

    expect($declaredHandicap->fresh()->has_handicap)->toBeTrue();
    expect($declaredNoHandicap->fresh()->has_handicap)->toBeFalse();
    expect($neverAnswered->fresh()->has_handicap)->toBeNull();
});

test('should_strip_handicap_from_export_for_non_privileged_roles', function () {
    $coach = exportingUserWithRoles(['coach']);

    $this->actingAs($coach)
        ->get('/admin/users/export?fields=name,email,has_handicap,cin,phone,role')
        ->assertOk();

    Excel::assertDownloaded(expectedExportFilename(), function ($export) {
        expect($export->headings())->toBe(['Name', 'Email']);

        return true;
    });
});

test('should_include_handicap_in_export_for_admins', function () {
    $admin = exportingUserWithRoles(['admin']);

    $this->actingAs($admin)
        ->get('/admin/users/export?fields=name,has_handicap,cin')
        ->assertOk();

    Excel::assertDownloaded(expectedExportFilename(), function ($export) {
        expect($export->headings())->toBe(['Name', 'Has handicap', 'Cin']);

        return true;
    });
});

test('exported handicap column reads Yes, No or blank', function () {
    $admin = exportingUserWithRoles(['admin']);
    User::factory()->create(['name' => 'Has handicap', 'has_handicap' => true]);
    User::factory()->create(['name' => 'No handicap', 'has_handicap' => false]);
    User::factory()->create(['name' => 'Unknown handicap']);

    $this->actingAs($admin)
        ->get('/admin/users/export?fields=name,has_handicap')
        ->assertOk();

    Excel::assertDownloaded(expectedExportFilename(), function ($export) {
        $rowsByName = collect(User::all())
            ->mapWithKeys(fn (User $user) => [$user->name => $export->map($user)[1]]);

        expect($rowsByName['Has handicap'])->toBe('Yes');
        expect($rowsByName['No handicap'])->toBe('No');
        expect($rowsByName['Unknown handicap'])->toBe('');

        return true;
    });
});
