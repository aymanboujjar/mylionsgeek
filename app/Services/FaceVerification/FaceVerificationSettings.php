<?php

namespace App\Services\FaceVerification;

final class FaceVerificationSettings
{
    public function isProviderReady(): bool
    {
        return $this->credential('key') !== null
            && $this->credential('secret') !== null
            && $this->credential('region') !== null
            && $this->minSimilarity() !== null;
    }

    public function minSimilarity(): ?float
    {
        $raw = config('face.min_similarity');

        if (! is_numeric($raw)) {
            return null;
        }

        $value = (float) $raw;

        if ($value < 0.0 || $value > 100.0) {
            return null;
        }

        return $value;
    }

    public function minFaceConfidence(): float
    {
        return (float) config('face.min_face_confidence', 90);
    }

    public function minBrightness(): float
    {
        return (float) config('face.min_brightness', 20);
    }

    public function minSharpness(): float
    {
        return (float) config('face.min_sharpness', 20);
    }

    public function enrollmentDisk(): string
    {
        $disk = config('face.enrollment_disk', 'face_enrollments');

        return is_string($disk) && $disk !== '' ? $disk : 'face_enrollments';
    }

    private function credential(string $key): ?string
    {
        $value = config('services.rekognition.'.$key);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $value;
    }
}
