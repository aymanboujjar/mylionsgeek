<?php

namespace App\Services\FaceVerification;

use App\Models\User;
use Illuminate\Http\UploadedFile;

interface FaceVerificationService
{
    /**
     * Compare the authenticated user against a submitted live photo.
     *
     * Only {@see FaceVerificationResult::Verified} may authorize a check-in write.
     */
    public function verify(User $user, UploadedFile $livePhoto): FaceVerificationResult;
}
