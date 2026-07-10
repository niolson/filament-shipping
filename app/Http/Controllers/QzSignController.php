<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class QzSignController extends Controller
{
    /**
     * The complete set of QZ Tray call names this application legitimately signs.
     *
     * This is an exact allow-list — deliberately not prefix-based. It covers only
     * the printer calls (find/print) and the scale integration's HID device
     * lifecycle (list/claim/release/open/close stream). Crucially it does NOT
     * include the HID data-transfer calls (hid.sendData, hid.readData,
     * hid.sendFeatureReport, hid.getFeatureReport) that the app never uses — nor
     * any file/serial/usb call — so the endpoint cannot be abused to mint
     * replayable signatures for reading from or writing to workstation hardware.
     * See security review issue 03.
     *
     * @var list<string>
     */
    private const ALLOWED_CALLS = [
        'printers.find',
        'print',
        'hid.listDevices',
        'hid.claimDevice',
        'hid.releaseDevice',
        'hid.openStream',
        'hid.closeStream',
    ];

    public function sign(Request $request)
    {
        $validated = $request->validate([
            'request' => 'required|string|max:2048',
        ]);

        if (! $this->isSignableCall($validated['request'])) {
            logger()->warning('QZ Tray signing rejected: payload is not an allowed QZ Tray call', [
                'user_id' => $request->user()?->getAuthIdentifier(),
            ]);

            return response()->json(['error' => 'Unsupported signing request'], 422);
        }

        $privateKeyPath = storage_path('app/private/qz-private-key.pem');

        if (! file_exists($privateKeyPath)) {
            logger()->error('QZ Tray signing failed: private key file not found', ['path' => $privateKeyPath]);

            return response()->json(['error' => 'Signing service unavailable'], 500);
        }

        $privateKey = openssl_pkey_get_private(file_get_contents($privateKeyPath));

        if (! $privateKey) {
            logger()->error('QZ Tray signing failed: invalid private key', ['path' => $privateKeyPath]);

            return response()->json(['error' => 'Signing service unavailable'], 500);
        }

        $signature = null;
        openssl_sign($request->input('request'), $signature, $privateKey, OPENSSL_ALGO_SHA512);

        return response(base64_encode($signature))
            ->header('Content-Type', 'text/plain');
    }

    /**
     * Confirm the payload decodes to a QZ Tray call request whose `call` is on the
     * allow-list, without re-serializing it — the exact bytes must still be what
     * gets signed so the signature verifies against QZ Tray's own copy.
     */
    private function isSignableCall(string $payload): bool
    {
        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            return false;
        }

        $call = $decoded['call'] ?? null;

        if (! is_string($call) || $call === '') {
            return false;
        }

        return in_array($call, self::ALLOWED_CALLS, true);
    }
}
