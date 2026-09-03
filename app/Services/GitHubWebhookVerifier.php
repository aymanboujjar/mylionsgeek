<?php

namespace App\Services;

use Illuminate\Http\Request;

class GitHubWebhookVerifier
{
    /**
     * Configured GitHub webhook secret, or null when missing/blank.
     */
    public function configuredSecret(): ?string
    {
        $secret = config('services.github.webhook_secret');

        if (! is_string($secret) || trim($secret) === '') {
            return null;
        }

        return $secret;
    }

    /**
     * Validate X-Hub-Signature-256 against the raw request body.
     *
     * Expected format: sha256=<64 hexadecimal characters>
     */
    public function signatureIsValid(Request $request, string $secret): bool
    {
        $signature = $request->header('X-Hub-Signature-256');

        if (! is_string($signature) || $signature === '') {
            return false;
        }

        if (! preg_match('/^sha256=([A-Fa-f0-9]{64})$/', $signature, $matches)) {
            return false;
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, strtolower($matches[1]));
    }
}
