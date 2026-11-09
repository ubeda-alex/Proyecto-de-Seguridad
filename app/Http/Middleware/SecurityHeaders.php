<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Defense-in-depth security headers (Fase 3 – PROTECT).
 *
 * Adds a Content-Security-Policy and hardening headers. XSS in user data is
 * already neutralized by Blade output encoding; this layer limits the blast
 * radius of any future sink and blocks clickjacking and base-tag hijacking.
 *
 * The policy is enforced by default. config('security.csp_mode') can switch
 * it to 'report-only' (header Content-Security-Policy-Report-Only) to audit a
 * change before enforcing it.
 *
 * Note: 'unsafe-inline' is kept in script-src because several existing views
 * (e.g. students/list, students/add, event-calendar) rely on inline scripts.
 * Removing it without refactoring those views would break normal operation,
 * so it is documented as an accepted residual rather than silently broken.
 * 'unsafe-eval' is not needed by any view and is not allowed.
 *
 * External origins are allow-listed host by host (never a wildcard) because
 * shared components load libraries from CDNs:
 *  - components/events/event-calendar: jQuery, moment, FullCalendar, toastr
 *    (JS + CSS) from cdnjs.cloudflare.com — used by home and events pages.
 *  - components/ckeditor/editor: CKEditor 5 from cdn.ckeditor.com — notices.
 */
class SecurityHeaders
{
    private const SCRIPT_CDNS = 'https://cdnjs.cloudflare.com https://cdn.ckeditor.com';
    private const STYLE_CDNS  = 'https://cdnjs.cloudflare.com';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' " . self::SCRIPT_CDNS,
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com " . self::STYLE_CDNS,
            "font-src 'self' data: https://fonts.gstatic.com",
            "img-src 'self' data:",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "frame-ancestors 'self'",
            "form-action 'self'",
        ]);

        $cspHeader = config('security.csp_mode', 'enforce') === 'report-only'
            ? 'Content-Security-Policy-Report-Only'
            : 'Content-Security-Policy';

        $headers = [
            $cspHeader                => $csp,
            'X-Content-Type-Options'  => 'nosniff',
            'X-Frame-Options'         => 'SAMEORIGIN',
            'Referrer-Policy'         => 'same-origin',
        ];

        foreach ($headers as $name => $value) {
            if (!$response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
