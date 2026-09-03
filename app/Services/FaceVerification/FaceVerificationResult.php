<?php

namespace App\Services\FaceVerification;

enum FaceVerificationResult: string
{
    case Verified = 'verified';
    case Rejected = 'rejected';
    case Unavailable = 'unavailable';

    public function allowsCheckIn(): bool
    {
        return $this === self::Verified;
    }
}
