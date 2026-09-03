<?php

namespace App\Services\FaceVerification;

use App\Models\FaceEnrollment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Server-side 1:1 face matching via Amazon Rekognition DetectFaces + CompareFaces.
 *
 * This is identity matching against a staff-enrolled private reference.
 * It is NOT liveness or anti-spoofing: printed photos and screen replay can still succeed.
 */
class RekognitionFaceVerificationService implements FaceVerificationService
{
    public function __construct(
        private readonly RekognitionClient $rekognition,
        private readonly FaceVerificationSettings $settings,
        private readonly RekognitionFaceAnalyzer $analyzer,
    ) {}

    public function verify(User $user, UploadedFile $livePhoto): FaceVerificationResult
    {
        if (! $this->settings->isProviderReady()) {
            return FaceVerificationResult::Unavailable;
        }

        $threshold = $this->settings->minSimilarity();
        if ($threshold === null) {
            return FaceVerificationResult::Unavailable;
        }

        $enrollment = FaceEnrollment::query()->where('user_id', $user->id)->first();
        if (! $enrollment) {
            return FaceVerificationResult::Unavailable;
        }

        try {
            $referenceBytes = $this->readEnrollmentBytes($enrollment);
            $liveBytes = $this->readUploadedBytes($livePhoto);
        } catch (FaceVerificationProviderException) {
            return FaceVerificationResult::Unavailable;
        }

        if ($liveBytes === '') {
            return FaceVerificationResult::Rejected;
        }

        try {
            $detected = $this->rekognition->detectFaces($liveBytes);
            $classification = $this->analyzer->classify($detected);

            if ($classification !== RekognitionFaceAnalyzer::OK) {
                return FaceVerificationResult::Rejected;
            }

            $comparison = $this->rekognition->compareFaces($liveBytes, $referenceBytes, $threshold);
        } catch (FaceVerificationProviderException) {
            return FaceVerificationResult::Unavailable;
        } catch (Throwable $e) {
            Log::warning('Face verification provider unavailable.', [
                'operation' => 'verify',
                'exception' => $e::class,
                'user_id' => $user->id,
            ]);

            return FaceVerificationResult::Unavailable;
        }

        $similarity = $this->bestMatchSimilarity($comparison);
        if ($similarity === null || $similarity < $threshold) {
            return FaceVerificationResult::Rejected;
        }

        return FaceVerificationResult::Verified;
    }

    /**
     * @param  array<string, mixed>  $comparison
     */
    private function bestMatchSimilarity(array $comparison): ?float
    {
        $matches = $comparison['FaceMatches'] ?? null;
        if (! is_array($matches) || $matches === []) {
            return null;
        }

        $best = null;
        foreach ($matches as $match) {
            if (! is_array($match) || ! isset($match['Similarity']) || ! is_numeric($match['Similarity'])) {
                continue;
            }

            $score = (float) $match['Similarity'];
            if ($best === null || $score > $best) {
                $best = $score;
            }
        }

        return $best;
    }

    private function readEnrollmentBytes(FaceEnrollment $enrollment): string
    {
        $disk = $enrollment->disk !== '' ? $enrollment->disk : $this->settings->enrollmentDisk();

        try {
            if (! Storage::disk($disk)->exists($enrollment->path)) {
                throw new FaceVerificationProviderException('Enrollment missing.');
            }

            $bytes = Storage::disk($disk)->get($enrollment->path);
        } catch (FaceVerificationProviderException $e) {
            throw $e;
        } catch (Throwable) {
            throw new FaceVerificationProviderException('Enrollment unreadable.');
        }

        if (! is_string($bytes) || $bytes === '') {
            throw new FaceVerificationProviderException('Enrollment unreadable.');
        }

        return $bytes;
    }

    private function readUploadedBytes(UploadedFile $file): string
    {
        $bytes = $file->getContent();

        return is_string($bytes) ? $bytes : '';
    }
}
