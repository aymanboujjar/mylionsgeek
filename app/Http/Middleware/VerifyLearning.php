<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyLearning
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('services.learning.secret');
        $provided = $request->header('x-api-key');

        if (! is_string($expected) || $expected === '') {
            return $this->unauthorized();
        }

        if (! is_string($provided) || $provided === '') {
            return $this->unauthorized();
        }

        if (! hash_equals($expected, $provided)) {
            return $this->unauthorized();
        }

        return $next($request);
    }

    private function unauthorized(): Response
    {
        return response()->json([
            'status' => 'error',
            'message' => 'unauthorized',
        ], 401);
    }
}
