<?php

namespace App\Services;

use App\Exceptions\FaceVerificationException;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class FaceVerificationService
{
    private const STAFF_BYPASS_ROLES = [
        'admin',
        'super_admin',
        'moderateur',
        'coach',
        'studio_responsable',
    ];

    public function shouldBypass(User $user): bool
    {
        $roles = is_array($user->role)
            ? $user->role
            : [$user->role];

        return (bool) array_intersect($roles, self::STAFF_BYPASS_ROLES);
    }

    /**
     * Compare a live check-in photo against the student's reference image via Face++.
     *
     * Live photo bytes are processed in memory only — never written to disk.
     *
     * @return array{passed: bool, confidence: float, method: string}
     *
     * @throws FaceVerificationException
     */
    public function verify(User $student, UploadedFile $livePhoto): array
    {
        if ($student->image === null || trim((string) $student->image) === '') {
            throw new FaceVerificationException('No reference photo on file for this student.');
        }

        $relativePath = $this->resolveReferenceRelativePath((string) $student->image);
        if ($relativePath === null) {
            throw new FaceVerificationException('Student reference photo not found on disk.');
        }

        $absolutePath = Storage::disk('public')->path($relativePath);

        $referenceBytes = file_get_contents($absolutePath);
        if ($referenceBytes === false || $referenceBytes === '') {
            throw new FaceVerificationException('Student reference photo not found on disk.');
        }

        $liveBytes = $livePhoto->get();
        $liveFilename = $livePhoto->getClientOriginalName() ?: 'live.jpg';
        $referenceFilename = basename($relativePath) ?: 'reference.jpg';

        try {
            $response = Http::timeout(15)
                ->attach('image_file1', $referenceBytes, $referenceFilename)
                ->attach('image_file2', $liveBytes, $liveFilename)
                ->post(config('face_verification.api_url'), [
                    'api_key' => config('face_verification.api_key'),
                    'api_secret' => config('face_verification.api_secret'),
                ]);
        } catch (ConnectionException $e) {
            throw new FaceVerificationException(
                'Face verification service is currently unavailable.',
                0,
                $e,
            );
        }

        if ($response->status() !== 200) {
            $errorMessage = $response->json('error_message');
            throw new FaceVerificationException(
                is_string($errorMessage) && $errorMessage !== ''
                    ? $errorMessage
                    : 'Face verification service is currently unavailable.',
            );
        }

        $confidence = (float) $response->json('confidence', 0);
        $threshold = (float) config('face_verification.threshold', 80);

        return [
            'passed' => $confidence >= $threshold,
            'confidence' => $confidence,
            'method' => 'face-match',
        ];
    }

    /**
     * Resolve users.image to a public-disk relative path.
     * DB often stores bare filenames; files live under img/profile/.
     */
    private function resolveReferenceRelativePath(string $image): ?string
    {
        $normalized = ltrim(str_replace('\\', '/', $image), '/');
        if (str_starts_with($normalized, 'storage/')) {
            $normalized = substr($normalized, strlen('storage/'));
        }

        $candidates = [];
        if ($normalized !== '') {
            $candidates[] = $normalized;
        }

        $basename = basename($normalized);
        if ($basename !== '' && $basename !== '.' && $basename !== '..') {
            $profilePath = 'img/profile/'.$basename;
            if ($profilePath !== $normalized) {
                $candidates[] = $profilePath;
            }
        }

        foreach ($candidates as $candidate) {
            if (is_file(Storage::disk('public')->path($candidate))) {
                return $candidate;
            }
        }

        return null;
    }
}
