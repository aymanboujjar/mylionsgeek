<?php

return [
    'api_key' => env('FACEPP_API_KEY'),
    'api_secret' => env('FACEPP_API_SECRET'),
    'api_url' => env('FACEPP_API_URL',
        'https://api-us.faceplusplus.com/facepp/v3/compare'),
    'threshold' => (float) env('FACE_MATCH_THRESHOLD', 80),
];
