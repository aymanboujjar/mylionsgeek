<?php

namespace App\Services\FaceVerification;

use Aws\Rekognition\RekognitionClient as AwsSdkRekognitionClient;
use Illuminate\Support\Facades\Log;
use Throwable;

class AwsRekognitionClient implements RekognitionClient
{
    public function detectFaces(string $imageBytes): array
    {
        try {
            return $this->sdk()->detectFaces([
                'Image' => ['Bytes' => $imageBytes],
                'Attributes' => ['DEFAULT'],
            ])->toArray();
        } catch (Throwable $e) {
            $this->logFailure('detect_faces', $e);
            throw new FaceVerificationProviderException('Face provider unavailable.');
        }
    }

    public function compareFaces(string $sourceBytes, string $targetBytes, float $similarityThreshold): array
    {
        try {
            return $this->sdk()->compareFaces([
                'SourceImage' => ['Bytes' => $sourceBytes],
                'TargetImage' => ['Bytes' => $targetBytes],
                'SimilarityThreshold' => $similarityThreshold,
            ])->toArray();
        } catch (Throwable $e) {
            $this->logFailure('compare_faces', $e);
            throw new FaceVerificationProviderException('Face provider unavailable.');
        }
    }

    private function sdk(): AwsSdkRekognitionClient
    {
        $key = config('services.rekognition.key');
        $secret = config('services.rekognition.secret');
        $region = config('services.rekognition.region');

        if (! is_string($key) || $key === '' || ! is_string($secret) || $secret === '' || ! is_string($region) || $region === '') {
            throw new FaceVerificationProviderException('Face provider unavailable.');
        }

        return new AwsSdkRekognitionClient([
            'version' => '2016-06-27',
            'region' => $region,
            'credentials' => [
                'key' => $key,
                'secret' => $secret,
            ],
        ]);
    }

    private function logFailure(string $operation, Throwable $e): void
    {
        $awsCode = method_exists($e, 'getAwsErrorCode') ? $e->getAwsErrorCode() : null;

        Log::warning('Face verification provider unavailable.', [
            'operation' => $operation,
            'exception' => $e::class,
            'aws_code' => is_string($awsCode) ? $awsCode : null,
        ]);
    }
}
