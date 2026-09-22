<?php

use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();
    $this->withoutVite();
});

function roleEditUser(array $roles, array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => $roles,
        'status' => 'Studying',
        'email_verified_at' => now(),
        'account_state' => 0,
        'access_cowork' => 0,
        'access_studio' => 0,
        'access_scan' => 0,
    ], $overrides));
}

test('admin users index includes other admin accounts for privileged actors', function () {
    $admin = roleEditUser(['admin'], ['email' => 'role.list.admin@example.com', 'name' => 'List Admin']);
    $otherAdmin = roleEditUser(['admin'], ['email' => 'role.list.other@example.com', 'name' => 'Other Admin']);
    $student = roleEditUser(['student'], ['email' => 'role.list.student@example.com', 'name' => 'List Student']);

    $this->actingAs($admin)
        ->get('/admin/users')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/users/index')
            ->has('users')
            ->where('users', function ($users) use ($admin, $otherAdmin, $student) {
                $ids = collect($users)->pluck('id')->all();

                return in_array($admin->id, $ids, true)
                    && in_array($otherAdmin->id, $ids, true)
                    && in_array($student->id, $ids, true);
            })
        );
});

test('coach users index hides admin accounts', function () {
    $coach = roleEditUser(['coach'], ['email' => 'role.list.coach@example.com']);
    $admin = roleEditUser(['admin'], ['email' => 'role.list.hidden.admin@example.com', 'name' => 'Hidden Admin']);
    $student = roleEditUser(['student'], ['email' => 'role.list.visible.student@example.com', 'name' => 'Visible Student']);

    $this->actingAs($coach)
        ->get('/admin/users')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/users/index')
            ->where('users', function ($users) use ($admin, $student) {
                $ids = collect($users)->pluck('id')->all();

                return in_array($student->id, $ids, true)
                    && ! in_array($admin->id, $ids, true);
            })
        );
});

test('admin can change another admin roles', function () {
    $admin = roleEditUser(['admin'], ['email' => 'role.actor.admin@example.com']);
    $target = roleEditUser(['admin'], ['email' => 'role.target.admin@example.com']);

    $this->actingAs($admin)
        ->from('/admin/users')
        ->put('/students/update/'.$target->id, [
            'name' => $target->name,
            'email' => $target->email,
            'roles' => ['admin', 'coach'],
        ])
        ->assertRedirect();

    expect($target->fresh()->normalizedRoles())->toEqualCanonicalizing(['admin', 'coach']);
});

test('admin can demote another admin to student', function () {
    $admin = roleEditUser(['admin'], ['email' => 'role.demote.actor@example.com']);
    $target = roleEditUser(['admin'], ['email' => 'role.demote.target@example.com']);

    $this->actingAs($admin)
        ->from('/admin/users')
        ->put('/students/update/'.$target->id, [
            'name' => $target->name,
            'email' => $target->email,
            'roles' => ['student'],
        ])
        ->assertRedirect();

    expect($target->fresh()->normalizedRoles())->toBe(['student']);
});

test('admin cannot change their own roles', function () {
    $admin = roleEditUser(['admin'], ['email' => 'role.self.admin@example.com']);

    $this->actingAs($admin)
        ->from('/admin/users')
        ->put('/students/update/'.$admin->id, [
            'name' => $admin->name,
            'email' => $admin->email,
            'roles' => ['student'],
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('roles');

    expect($admin->fresh()->normalizedRoles())->toBe(['admin']);
});

test('admin can still update own profile without touching roles', function () {
    $admin = roleEditUser(['admin'], [
        'email' => 'role.self.profile@example.com',
        'name' => 'Old Name',
    ]);

    $this->actingAs($admin)
        ->from('/admin/users')
        ->put('/students/update/'.$admin->id, [
            'name' => 'New Name',
            'email' => $admin->email,
        ])
        ->assertRedirect();

    $admin->refresh();
    expect($admin->name)->toBe('New Name')
        ->and($admin->normalizedRoles())->toBe(['admin']);
});

test('admin user show page renders without undefined viewer error', function () {
    $admin = roleEditUser(['admin'], ['email' => 'role.show.admin@example.com']);
    $target = roleEditUser(['student', 'coach'], ['email' => 'role.show.target@example.com']);

    $this->actingAs($admin)
        ->get('/admin/users/'.$target->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/users/[id]')
            ->has('user')
            ->has('canEnrollFace')
        );
});
