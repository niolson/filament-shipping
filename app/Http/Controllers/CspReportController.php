<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class CspReportController extends Controller
{
    /**
     * Receive browser CSP violation reports (report-uri) and log them to the
     * dedicated `csp` channel (storage/logs/csp.log) for local policy tuning.
     *
     * Registered only in local/testing (see routes/web.php); the browser posts
     * a JSON body with Content-Type "application/csp-report".
     */
    public function __invoke(Request $request): Response
    {
        $payload = json_decode($request->getContent(), true);
        $report = is_array($payload) ? ($payload['csp-report'] ?? $payload) : null;

        Log::channel('csp')->warning(
            'CSP violation',
            is_array($report) ? $report : ['raw' => $request->getContent()]
        );

        return response()->noContent();
    }
}
