<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\CertificateImageGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class GenerateCertificatePngs extends Command
{
    protected $signature = 'certificates:generate-pngs {--force : Overwrite existing PNGs}';

    protected $description = 'Generate PNG certificate images for all certified users (backfill).';

    public function handle(CertificateImageGenerator $generator): int
    {
        $users = User::query()
            ->whereNotNull('certified_at')
            ->whereNotNull('certified_training_id')
            ->with('formation')
            ->get();

        if ($users->isEmpty()) {
            $this->info('No certified users found.');
            return self::SUCCESS;
        }

        $converted = 0;
        $skipped   = 0;
        $failed    = 0;

        foreach ($users as $user) {
            $pngPath = 'images/certificationImages/' . $user->id . '.png';

            if (! $this->option('force') && Storage::disk('public')->exists($pngPath)) {
                $this->line("  <comment>SKIP</comment>  user {$user->id} ({$user->name}) — PNG already exists");
                $skipped++;
                continue;
            }

            $trainingTitle = $user->formation?->name ?? 'LionsGeek Program';
            $issuedDate    = $user->certified_at
                ? \Carbon\Carbon::parse($user->certified_at)->locale('fr_FR')->translatedFormat('d F Y')
                : now()->locale('fr_FR')->translatedFormat('d F Y');

            $ok = $generator->generate(
                userId: $user->id,
                studentName: (string) ($user->name ?? ''),
                field: (string) ($user->field ?? ''),
                trainingTitle: $trainingTitle,
                issuedDate: $issuedDate,
            );

            if ($ok) {
                $this->line("  <info>OK</info>    user {$user->id} ({$user->name})");
                $converted++;
            } else {
                $this->line("  <error>FAIL</error>  user {$user->id} ({$user->name})");
                $failed++;
            }
        }

        $this->newLine();
        $this->info("Done. Converted: {$converted} | Skipped: {$skipped} | Failed: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
