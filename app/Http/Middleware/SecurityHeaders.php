<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-XSS-Protection', '1; mode=block');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        $csp = implode('; ', [
            "default-src 'self'",
            // static.cloudflareinsights.com hosts the beacon.min.js that Cloudflare
            // Web Analytics auto-injects at the edge — must be allowlisted or the
            // browser blocks every page load with a CSP violation.
            "script-src 'self' cdn.jsdelivr.net cdnjs.cloudflare.com fonts.googleapis.com static.cloudflareinsights.com 'unsafe-inline' 'unsafe-eval'",
            "style-src 'self' 'unsafe-inline' fonts.googleapis.com cdnjs.cloudflare.com",
            "img-src 'self' data:",
            "font-src 'self' fonts.gstatic.com cdnjs.cloudflare.com",
            // Livewire/Alpine talk back to 'self'; the CF beacon POSTs to cloudflareinsights.com.
            "connect-src 'self' cloudflareinsights.com",
        ]);
        $response->headers->set('Content-Security-Policy', $csp);

        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
