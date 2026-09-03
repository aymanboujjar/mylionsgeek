<?php

use App\Services\FaceVerification\FaceVerificationSettings;

test('blank aws credentials are not provider-ready', function () {
    config([
        'services.rekognition.key' => '',
        'services.rekognition.secret' => '',
        'services.rekognition.region' => 'us-east-1',
        'face.min_similarity' => 90,
    ]);

    expect(app(FaceVerificationSettings::class)->isProviderReady())->toBeFalse();
});

test('non-numeric similarity is invalid', function () {
    config([
        'services.rekognition.key' => 'k',
        'services.rekognition.secret' => 's',
        'services.rekognition.region' => 'us-east-1',
        'face.min_similarity' => 'high',
    ]);

    $settings = app(FaceVerificationSettings::class);

    expect($settings->minSimilarity())->toBeNull()
        ->and($settings->isProviderReady())->toBeFalse();
});
