<?php

namespace App\Services;

use App\Models\FaceEnrollment;
use App\Models\User;
use App\Services\FaceVerification\FaceEnrollmentRejectedException;
use App\Services\FaceVerification\FaceVerificationProviderException;
use App\Services\FaceVerification\FaceVerificationSettings;
use App\Services\FaceVerification\RekognitionClient;
use App\Services\FaceVerification\RekognitionFaceAnalyzer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class FaceEnrollmentService
{
    /**
     * @return array<int, string>
     */
    public static function referencePhotoRules(): array
    {
        return ['required', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:4096'];
    }

    public function __construct(
        private readonly RekognitionClient $rekognition,
        private readonly FaceVerificationSettings $settings,
        private readonly RekognitionFaceAnalyzer $analyzer,
    ) {}

    public function enroll(User $student, User $staff, UploadedFile $referencePhoto): FaceEnrollment
    {
        if (! $this->settings->isProviderReady()) {
            throw new FaceVerificationProviderException('Face enrollment provider unavailable.');
        }

        $bytes = $referencePhoto->getContent();
        if (! is_string($bytes) || $bytes === '') {
            throw new FaceEnrollmentRejectedException('Enrollment photo is not a usable image.');
        }

        $this->assertNotAnimated($bytes, $referencePhoto);

        try {
            $detected = $this->rekognition->detectFaces($bytes);
        } catch (FaceVerificationProviderException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::warning('Face enrollment provider unavailable.', [
                'operation' => 'detect_faces',
                'exception' => $e::class,
            ]);
            throw new FaceVerificationProviderException('Face enrollment provider unavailable.');
        }

        $classification = $this->analyzer->classify($detected);
        if ($classification === RekognitionFaceAnalyzer::NONE) {
            throw new FaceEnrollmentRejectedException('Enrollment photo must contain a face.');
        }
        if ($classification === RekognitionFaceAnalyzer::MULTIPLE) {
            throw new FaceEnrollmentRejectedException('Enrollment photo must contain exactly one face.');
        }
        if ($classification !== RekognitionFaceAnalyzer::OK) {
            throw new FaceEnrollmentRejectedException('Enrollment photo quality is too low.');
        }

        $disk = $this->settings->enrollmentDisk();
        $extension = $this->safeExtension($referencePhoto);
        $path = $student->id.'/'.Str::uuid()->toString().'.'.$extension;

        try {
            $stored = Storage::disk($disk)->put($path, $bytes);
        } catch (Throwable) {
            throw new FaceVerificationProviderException('Unable to store enrollment photo.');
        }

        if (! $stored) {
            throw new FaceVerificationProviderException('Unable to store enrollment photo.');
        }

        $previous = FaceEnrollment::query()->where('user_id', $student->id)->first();
        $previousDisk = $previous?->disk;
        $previousPath = $previous?->path;

        $enrollment = FaceEnrollment::query()->updateOrCreate(
            ['user_id' => $student->id],
            [
                'disk' => $disk,
                'path' => $path,
                'enrolled_by' => $staff->id,
                'enrolled_at' => now(),
            ],
        );

        if (is_string($previousPath) && $previousPath !== '' && $previousPath !== $path) {
            try {
                Storage::disk($previousDisk ?: $disk)->delete($previousPath);
            } catch (Throwable) {
                Log::warning('Failed to delete replaced face enrollment file.', [
                    'user_id' => $student->id,
                ]);
            }
        }

        return $enrollment;
    }

    private function assertNotAnimated(string $bytes, UploadedFile $file): void
    {
        $mime = strtolower((string) $file->getMimeType());
        if (str_contains($mime, 'gif')) {
            throw new FaceEnrollmentRejectedException('Animated images are not allowed.');
        }

        if (str_contains($mime, 'webp') || str_ends_with(strtolower($file->getClientOriginalExtension()), 'webp')) {
            if (str_contains($bytes, 'ANIM') || str_contains($bytes, 'ANMF')) {
                throw new FaceEnrollmentRejectedException('Animated images are not allowed.');
            }
        }
    }

    private function safeExtension(UploadedFile $file): string
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());

        return in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true) ? $extension : 'jpg';
    }
}
