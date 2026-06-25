<?php

namespace App\Http\Integrations\USPS;

use App\Contracts\OAuthProvider;
use App\Models\CarrierAccount;

class UspsOAuthProvider implements OAuthProvider
{
    public function getKey(): string
    {
        return 'usps';
    }

    public function getDisplayName(): string
    {
        return 'USPS';
    }

    public function getSupportedAuthModes(): array
    {
        return ['client_credentials', 'authorization_code'];
    }

    public function revokeToken(string $accessToken): void
    {
        // USPS does not provide a token revocation endpoint
    }

    /**
     * Decode the JWT and auto-populate CRID, MID, and EPS account from the
     * account info USPS embeds in the access token, writing to the account's
     * JSON credentials.
     */
    public function afterConnectToAccount(string $accessToken, CarrierAccount $account): void
    {
        $parts = explode('.', $accessToken);

        if (count($parts) !== 3) {
            return;
        }

        $payload = json_decode(
            base64_decode(str_pad(strtr($parts[1], '-_', '+/'), strlen($parts[1]) % 4, '=', STR_PAD_RIGHT)),
            true
        );

        if (! is_array($payload)) {
            return;
        }

        if (! empty($payload['crid'])) {
            $account->mergeCredential('crid', (string) $payload['crid']);
        }

        $midsRaw = $payload['mail_owners'][0]['mids'] ?? null;
        if ($midsRaw) {
            $masterMid = trim(explode(',', (string) $midsRaw)[0]);
            $account->mergeCredential('mid', $masterMid);
        }

        $epsAccount = $payload['payment_accounts']['accounts'] ?? null;
        if ($epsAccount) {
            $account->mergeCredential('eps_account', (string) $epsAccount);
        }

        $account->save();
    }
}
