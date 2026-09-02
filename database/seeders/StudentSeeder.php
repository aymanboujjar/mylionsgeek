<?php

namespace Database\Seeders;

use App\Models\Formation;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class StudentSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('StudentSeeder is intended for local/testing only. Skipping.');
            return;
        }

        $formation = Formation::query()
            ->where('category', 'coding')
            ->where('promo', '5')
            ->where('name', 'like', '%Coding 2%')
            ->first();

        if (! $formation) {
            $formation = Formation::create([
                'name' => 'Promo 5 -  Coding 2',
                'img' => 'default_training.jpg',
                'category' => 'coding',
                'promo' => '5',
                'start_time' => now()->toDateString(),
                'end_time' => now()->addMonths(6)->toDateString(),
            ]);

            $this->command->warn("Formation \"Promo 5 - Coding 2\" was missing and has been created (id: {$formation->id}).");
        }

        $students = [
            ['name' => 'Demo Student', 'email' => 'student@example.com'],
            ['name' => 'Alice Student', 'email' => 'alice.student@example.com'],
            ['name' => 'Bob Student', 'email' => 'bob.student@example.com'],
            ['name' => 'Charlie Student', 'email' => 'charlie.student@example.com'],
            ['name' => 'Diana Student', 'email' => 'diana.student@example.com'],
        ];

        foreach ($students as $data) {
            $user = User::query()->firstOrNew(['email' => $data['email']]);
            $user->forceFill([
                'name' => $data['name'],
                'password' => Hash::make('password'),
                'role' => ['student'],
                'status' => 'Studying',
                'formation_id' => $formation->id,
                'promo' => 5,
                'account_state' => 0,
                'access_studio' => 0,
                'access_cowork' => 0,
                'image' => 'pdp.png',
                'email_verified_at' => now(),
            ])->save();
        }

        $count = count($students);

        $this->command->info("✅ {$count} student accounts seeded for \"{$formation->name}\" (formation id: {$formation->id}).");
        $this->command->info('   Login password for all seeded students: password');
    }
}
