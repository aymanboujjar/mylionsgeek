<?php

namespace App\Services\FaceVerification;

interface RekognitionClient
{
    /**
     * @return array<string, mixed>
     */
    public function detectFaces(string $imageBytes): array;

    /**
     * @return array<string, mixed>
     */
    public function compareFaces(string $sourceBytes, string $targetBytes, float $similarityThreshold): array;
}
