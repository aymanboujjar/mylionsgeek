<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Face verification (attendance check-in)
    |--------------------------------------------------------------------------
    |
    | Amazon Rekognition CompareFaces returns a similarity score from 0–100.
    | FACE_VERIFICATION_MIN_SIMILARITY must be calibrated with real on-site
    | captures before production use. An invalid value fail-closes verification.
    |
    | DetectFaces + CompareFaces is 1:1 identity matching against a
    | staff-enrolled private reference. It is NOT liveness or anti-spoofing.
    | Printed photos and screen replay can still succeed.
    |
    | Do not use users.image as the biometric reference.
    |
    */

    'min_similarity' => env('FACE_VERIFICATION_MIN_SIMILARITY', 90),

    'min_face_confidence' => 90.0,

    'min_brightness' => 20.0,

    'min_sharpness' => 20.0,

    'enrollment_disk' => env('FACE_ENROLLMENT_DISK', 'face_enrollments'),

];
