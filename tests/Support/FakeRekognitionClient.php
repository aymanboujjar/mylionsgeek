<?php

namespace Tests\Support;

use App\Services\FaceVerification\FaceVerificationProviderException;
use App\Services\FaceVerification\RekognitionClient;

final class FakeRekognitionClient implements RekognitionClient
{
    public int $detectFaceCount = 1;

    public float $confidence = 99.0;

    public float $brightness = 80.0;

    public float $sharpness = 80.0;

    public ?float $similarity = 99.0;

    public bool $throwOnDetect = false;

    public bool $throwOnCompare = false;

    /** @var list<float> */
    public array $compareThresholds = [];

    public int $detectCalls = 0;

    public int $compareCalls = 0;

    public function detectFaces(string $imageBytes): array
    {
        $this->detectCalls++;

        if ($this->throwOnDetect) {
            throw new FaceVerificationProviderException('Face provider unavailable.');
        }

        $details = [];
        for ($i = 0; $i < $this->detectFaceCount; $i++) {
            $details[] = [
                'Confidence' => $this->confidence,
                'Quality' => [
                    'Brightness' => $this->brightness,
                    'Sharpness' => $this->sharpness,
                ],
            ];
        }

        return ['FaceDetails' => $details];
    }

    public function compareFaces(string $sourceBytes, string $targetBytes, float $similarityThreshold): array
    {
        $this->compareCalls++;
        $this->compareThresholds[] = $similarityThreshold;

        if ($this->throwOnCompare) {
            throw new FaceVerificationProviderException('Face provider unavailable.');
        }

        if ($this->similarity === null || $this->similarity < $similarityThreshold) {
            return ['FaceMatches' => []];
        }

        return [
            'FaceMatches' => [
                ['Similarity' => $this->similarity],
            ],
        ];
    }
}
