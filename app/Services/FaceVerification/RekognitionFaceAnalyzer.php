<?php

namespace App\Services\FaceVerification;

final class RekognitionFaceAnalyzer
{
    public const OK = 'ok';

    public const NONE = 'none';

    public const MULTIPLE = 'multiple';

    public const LOW_QUALITY = 'low_quality';

    public function __construct(
        private readonly FaceVerificationSettings $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $detectFacesResult
     */
    public function classify(array $detectFacesResult): string
    {
        $faces = $detectFacesResult['FaceDetails'] ?? null;

        if (! is_array($faces) || $faces === []) {
            return self::NONE;
        }

        if (count($faces) !== 1) {
            return self::MULTIPLE;
        }

        $face = $faces[0];
        if (! is_array($face) || ! $this->qualityIsAcceptable($face)) {
            return self::LOW_QUALITY;
        }

        return self::OK;
    }

    /**
     * @param  array<string, mixed>  $face
     */
    private function qualityIsAcceptable(array $face): bool
    {
        $confidence = $face['Confidence'] ?? null;
        if (! is_numeric($confidence) || (float) $confidence < $this->settings->minFaceConfidence()) {
            return false;
        }

        $quality = $face['Quality'] ?? null;
        if (! is_array($quality)) {
            return true;
        }

        $brightness = $quality['Brightness'] ?? null;
        if (is_numeric($brightness) && (float) $brightness < $this->settings->minBrightness()) {
            return false;
        }

        $sharpness = $quality['Sharpness'] ?? null;
        if (is_numeric($sharpness) && (float) $sharpness < $this->settings->minSharpness()) {
            return false;
        }

        return true;
    }
}
