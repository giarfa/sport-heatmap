<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends the baseline security headers on every web response, 404s included.
 *
 * No page can be framed by another site, which is what makes the Garmin import
 * review's Overwrite controls impossible to trick-click (UI redress is not
 * stopped by CSRF tokens), and browsers neither sniff content types nor leak
 * the full URL to other origins. HSTS is sent on secure requests only.
 */
class SetSecurityHeaders
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        /*
         * Each header is set only when the response does not carry it yet. The
         * Larapilot development dashboard sets SAMEORIGIN and a
         * `frame-ancestors 'self'` policy on purpose, so its design viewer can
         * iframe the mockups. A second Content-Security-Policy would be enforced
         * beside it and its 'none' would win, so the viewer would stop loading.
         */
        $headers = [
            'X-Frame-Options' => 'DENY',
            /*
             * Only frame-ancestors: a default-src or script-src would block the
             * Vite assets and the layout's inline theme script, and nothing
             * here needs a wider policy.
             */
            'Content-Security-Policy' => "frame-ancestors 'none'",
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'same-origin',
        ];

        /*
         * No includeSubDomains and no preload: this host's other subdomains are
         * unknown and both are close to irreversible once a browser has cached
         * them. isSecure() is only true behind a TLS-terminating proxy when that
         * proxy is trusted (TrustProxies); deployment is not defined yet, so
         * until it is the header is simply never sent rather than sent wrongly.
         */
        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000';
        }

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
