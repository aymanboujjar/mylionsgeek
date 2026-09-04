<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * One-time move of CVs from the public disk / public/storage into the private documents disk.
 */
class MigrateResumesToPrivateDisk extends Command
{
    protected $signature = 'resumes:migrate-private
        {--apply : Copy files onto the private documents disk (default is a dry run)}
        {--delete-public : After a successful copy with --apply, remove the public copy}';

    protected $description = 'Move user resumes from public storage onto the private documents disk.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $deletePublic = (bool) $this->option('delete-public');
        $private = Storage::disk(User::RESUME_DISK);
        $public = Storage::disk('public');

        $users = User::query()
            ->whereNotNull('resume')
            ->where('resume', '!=', '')
            ->orderBy('id')
            ->get(['id', 'name', 'resume']);

        $copied = 0;
        $already = 0;
        $missing = 0;

        foreach ($users as $user) {
            $relative = $user->resumeStoragePath();
            if (! $relative) {
                $missing++;
                continue;
            }

            if ($private->exists($relative)) {
                $already++;
                if ($apply && $deletePublic) {
                    $this->removePublicCopies($public, $relative);
                }
                continue;
            }

            $source = $this->findPublicSource($public, $relative);
            if ($source === null) {
                $this->warn("Missing source for user #{$user->id} ({$user->resume})");
                $missing++;
                continue;
            }

            $this->line(($apply ? 'Copy' : 'Would copy')." user #{$user->id}: {$source['label']} → documents:{$relative}");

            if ($apply) {
                $private->put($relative, $source['contents']);
                $copied++;
                if ($deletePublic) {
                    $this->removePublicCopies($public, $relative);
                }
            }
        }

        $this->newLine();
        $this->info("Users with resume: {$users->count()}");
        $this->info(($apply ? 'Copied' : 'Would copy').": {$copied}");
        $this->info("Already on private disk: {$already}");
        $this->info("Missing source: {$missing}");

        if (! $apply) {
            $this->warn('Dry run only. Re-run with --apply to copy files.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array{label: string, contents: string}|null
     */
    private function findPublicSource($public, string $relative): ?array
    {
        if ($public->exists($relative)) {
            return [
                'label' => 'public:'.$relative,
                'contents' => $public->get($relative),
            ];
        }

        $basename = basename($relative);
        foreach (['storage/resumes', 'storage/resume'] as $legacyDir) {
            $legacy = public_path($legacyDir.'/'.$basename);
            if (is_file($legacy) && is_readable($legacy)) {
                $contents = file_get_contents($legacy);
                if ($contents !== false) {
                    return [
                        'label' => $legacy,
                        'contents' => $contents,
                    ];
                }
            }
        }

        return null;
    }

    private function removePublicCopies($public, string $relative): void
    {
        $public->delete($relative);

        $basename = basename($relative);
        foreach (['storage/resumes', 'storage/resume'] as $legacyDir) {
            $legacy = public_path($legacyDir.'/'.$basename);
            if (is_file($legacy)) {
                @unlink($legacy);
            }
        }
    }
}
