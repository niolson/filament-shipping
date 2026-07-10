<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class QzProvisionScriptController extends Controller
{
    /**
     * Serve a QZ Tray workstation trust script with this site's URL baked in,
     * so operators can run it without arguments to silence QZ Tray's Allow/Block
     * prompt.
     *
     * Platforms:
     * - windows     raw PowerShell script (.ps1) for advanced/CLI use
     * - windows-cmd self-elevating .cmd wrapper around the .ps1 (double-clickable)
     * - unix        bash script (.sh) for macOS/Linux
     */
    public function download(Request $request, string $platform): Response
    {
        // Bake in the app's own configured URL, never the request's Host header.
        // This artifact is a self-elevating trust-provisioning script, so an
        // attacker-influenced Host must not be able to redirect the workstation's
        // QZ Tray trust setup to another host. See security review issue 10.
        $baseUrl = rtrim((string) config('app.url'), '/');

        if ($platform === 'windows-cmd') {
            return $this->fileResponse(
                $this->windowsLauncher($baseUrl),
                'install-qz-cert.cmd',
                'application/octet-stream',
            );
        }

        $files = [
            'windows' => ['install-qz-cert.ps1', 'text/plain'],
            'unix' => ['install-qz-cert.sh', 'application/x-sh'],
        ];

        if (! isset($files[$platform])) {
            abort(404);
        }

        [$filename, $contentType] = $files[$platform];

        $script = $this->bakedScript($filename, $baseUrl);

        // Windows PowerShell needs CRLF (its here-string tokenizer breaks on LF-only
        // files); the bash script must stay LF.
        if ($platform === 'windows') {
            $script = $this->toCrlf($script);
        }

        return $this->fileResponse($script, $filename, $contentType);
    }

    private function toCrlf(string $text): string
    {
        return str_replace("\n", "\r\n", str_replace("\r\n", "\n", $text));
    }

    /**
     * Read a provisioning script and substitute this site's URL.
     */
    private function bakedScript(string $filename, string $baseUrl): string
    {
        $path = base_path("scripts/qz-provision/{$filename}");

        if (! file_exists($path)) {
            abort(404);
        }

        return str_replace('__POLYBAG_URL__', $baseUrl, file_get_contents($path));
    }

    /**
     * Build a double-clickable Windows launcher: a batch header that self-elevates
     * via UAC, extracts the embedded PowerShell payload to a temp file, and runs it.
     */
    private function windowsLauncher(string $baseUrl): string
    {
        $header = [
            '@echo off',
            '>nul 2>&1 net session || (powershell -NoProfile -Command "Start-Process -Verb RunAs -FilePath \'%~f0\'" & exit /b)',
            'set "_PS=%TEMP%\\polybag-qz-trust.ps1"',
            'powershell -NoProfile -Command "Get-Content -LiteralPath \'%~f0\' | Select-Object -Skip __SKIP__ | Set-Content -LiteralPath $env:_PS -Encoding UTF8"',
            'powershell -NoProfile -ExecutionPolicy Bypass -File "%_PS%"',
            'del "%_PS%" >nul 2>&1 & exit /b',
        ];

        $header = str_replace('__SKIP__', (string) count($header), implode("\r\n", $header));

        // CRLF throughout so cmd.exe parses the header and PowerShell reads the payload.
        $payload = $this->toCrlf($this->bakedScript('install-qz-cert.ps1', $baseUrl));

        return $header."\r\n".$payload;
    }

    private function fileResponse(string $contents, string $filename, string $contentType): Response
    {
        return response($contents)
            ->header('Content-Type', $contentType)
            ->header('Content-Disposition', "attachment; filename=\"{$filename}\"")
            ->header('Cache-Control', 'no-store');
    }
}
