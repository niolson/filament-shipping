<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ContentSecurityPolicy
{
    /**
     * Content Security Policy for the Filament UI.
     *
     * Filament/Livewire/Alpine require 'unsafe-inline' and 'unsafe-eval'; QZ Tray
     * printing connects to wss://localhost. The webfont (DM Sans) and user
     * avatars are self-hosted, so no third-party origins are needed.
     *
     * Enforcing. In local, violations are still reported to CspReportController
     * (report-uri) so any stragglers are logged even though they are now blocked.
     * To temporarily revert to observe-only, append "-Report-Only" to HEADER.
     */
    private const HEADER = 'Content-Security-Policy';

    /**
     * @return array<string, list<string>>
     */
    private function directives(Request $request): array
    {
        $directives = [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", "'unsafe-inline'", "'unsafe-eval'"],
            'style-src' => ["'self'", "'unsafe-inline'"],
            'img-src' => ["'self'", 'data:', 'blob:'],
            'font-src' => ["'self'", 'data:'],
            'connect-src' => ["'self'", 'wss://localhost:*', 'ws://localhost:*'],
            'worker-src' => ["'self'", 'blob:'],
            'object-src' => ["'none'"],
            'frame-ancestors' => ["'self'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
        ];

        // In local development, assets are served by the Vite dev server (HMR)
        // rather than the built /build/ bundle, so allow its origin. Never added
        // in production, where everything is same-origin.
        if (app()->isLocal()) {
            $vite = 'https://'.$request->getHost().':5173';
            $viteWs = 'wss://'.$request->getHost().':5173';
            $directives['script-src'][] = $vite;
            $directives['style-src'][] = $vite;
            $directives['connect-src'][] = $vite;
            $directives['connect-src'][] = $viteWs;
        }

        return $directives;
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $policy = collect($this->directives($request))
            ->map(fn (array $sources, string $directive): string => $directive.' '.implode(' ', $sources))
            ->implode('; ');

        // Collect violation reports locally so the policy can be tuned before it
        // is enforced in production. Handled by CspReportController.
        if (app()->isLocal()) {
            $policy .= '; report-uri /csp-report';
        }

        $response->headers->set(self::HEADER, $policy);

        return $response;
    }
}
