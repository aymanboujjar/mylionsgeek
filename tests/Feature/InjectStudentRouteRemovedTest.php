<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('inject-student route is not registered and returns 404', function () {
    $this->withoutVite();

    expect(Route::has('inject-student'))->toBeFalse();

    $registered = collect(Route::getRoutes())->contains(function ($route) {
        return in_array('GET', $route->methods(), true)
            && trim($route->uri(), '/') === 'inject-student';
    });
    expect($registered)->toBeFalse();

    $before = User::query()->count();

    $response = $this->get('/inject-student');
    $response->assertNotFound();
    $response->assertDontSee('inserted_count', false);
    $response->assertDontSee('promo_synced_count', false);
    $response->assertDontSee('already_exists', false);

    expect(User::query()->count())->toBe($before);
});
