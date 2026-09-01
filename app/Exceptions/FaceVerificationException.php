<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Fail-closed signal when Face++ is unreachable or returns an unexpected error.
 * Distinct from a failed match (which returns passed=false).
 */
class FaceVerificationException extends RuntimeException
{
}
