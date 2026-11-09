<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Regression tests for the SecurityHeaders middleware (Fase 3).
 *
 * The CSP must keep its restrictive directives while still allowing the
 * CDN libraries used by shared components (event calendar, CKEditor);
 * otherwise the policy breaks normal operation of other modules.
 */
class SecurityHeadersTest extends TestCase
{
    private function cspDirectives(): array
    {
        $csp = $this->get('/login')->headers->get('Content-Security-Policy');
        $this->assertNotEmpty($csp, 'Content-Security-Policy header is missing');

        $directives = [];
        foreach (array_filter(array_map('trim', explode(';', $csp))) as $directive) {
            $parts = preg_split('/\s+/', $directive);
            $directives[array_shift($parts)] = $parts;
        }

        return $directives;
    }

    /** @test */
    public function csp_allows_cdn_libraries_used_by_shared_components()
    {
        $csp = $this->cspDirectives();

        // components/events/event-calendar (home, events) — jQuery, moment, FullCalendar, toastr
        $this->assertContains('https://cdnjs.cloudflare.com', $csp['script-src']);
        $this->assertContains('https://cdnjs.cloudflare.com', $csp['style-src']);
        // components/ckeditor/editor (notices/create)
        $this->assertContains('https://cdn.ckeditor.com', $csp['script-src']);
    }

    /** @test */
    public function csp_keeps_restrictive_directives()
    {
        $csp = $this->cspDirectives();

        $this->assertSame(["'self'"], $csp['default-src']);
        $this->assertSame(["'none'"], $csp['object-src']);
        $this->assertSame(["'self'"], $csp['base-uri']);
        $this->assertSame(["'self'"], $csp['frame-ancestors']);
        $this->assertSame(["'self'"], $csp['form-action']);
        $this->assertNotContains('*', $csp['script-src']);
        $this->assertNotContains("'unsafe-eval'", $csp['script-src']);
    }
}
