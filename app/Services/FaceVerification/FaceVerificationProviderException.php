<?php

namespace App\Services\FaceVerification;

use RuntimeException;

/**
 * Provider/config/storage failure. Must never be mapped to Verified.
 */
class FaceVerificationProviderException extends RuntimeException
{
}
