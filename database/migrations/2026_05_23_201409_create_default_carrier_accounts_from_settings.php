<?php

use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\CarrierAccountScope;
use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $this->migrateUsps();
        $this->migrateFedex();
        $this->migrateUps();
    }

    public function down(): void
    {
        logger()->warning('Rolling back create_default_carrier_accounts_from_settings: '
            .'global Settings carrier credentials cannot be restored. '
            .'CarrierAccount records created by this migration remain intact.');
    }

    /** Read a setting value directly from the DB (bypasses SettingsService Redis cache). */
    private function setting(string $key): mixed
    {
        return Setting::find($key)?->value;
    }

    /** Delete a list of setting keys from the DB. */
    private function deleteSettings(array $keys): void
    {
        Setting::whereIn('key', $keys)->delete();
    }

    private function ensureScope(CarrierAccount $account, int $carrierId): void
    {
        if (! CarrierAccountScope::where('carrier_account_id', $account->id)->exists()) {
            CarrierAccountScope::create([
                'carrier_account_id' => $account->id,
                'carrier_id' => $carrierId,
                'location_id' => null,
                'client_id' => null,
            ]);
        }
    }

    private function migrateUsps(): void
    {
        $carrier = Carrier::where('name', 'USPS')->first();
        if (! $carrier) {
            return;
        }

        $oauthToken = $this->setting('usps.oauth_access_token');
        $refreshToken = $this->setting('usps.oauth_refresh_token');
        $clientId = $this->setting('usps.client_id');
        $clientSecret = $this->setting('usps.client_secret');
        $crid = $this->setting('usps.crid');
        $mid = $this->setting('usps.mid');
        $epsAccount = $this->setting('usps.eps_account');

        if (! $oauthToken && ! $clientId && ! $crid) {
            return;
        }

        $account = CarrierAccount::where('carrier_id', $carrier->id)->first()
            ?? new CarrierAccount(['carrier_id' => $carrier->id, 'name' => 'USPS Default', 'active' => true]);

        $credentials = $account->credentials ?? [];
        if ($crid && empty($credentials['crid'])) {
            $credentials['crid'] = $crid;
        }
        if ($mid && empty($credentials['mid'])) {
            $credentials['mid'] = $mid;
        }
        if ($epsAccount && empty($credentials['eps_account'])) {
            $credentials['eps_account'] = $epsAccount;
        }
        $account->credentials = $credentials ?: null;

        $secrets = $account->secret_credentials ?? [];
        if ($oauthToken) {
            $secrets['oauth_token'] = $oauthToken;
            $secrets['auth_mode'] = 'authorization_code';
        }
        if ($refreshToken) {
            $secrets['refresh_token'] = $refreshToken;
        }
        if ($clientId) {
            $secrets['client_id'] = $clientId;
        }
        if ($clientSecret) {
            $secrets['client_secret'] = $clientSecret;
        }
        foreach (['oauth_token_expires_at', 'oauth_refresh_token_expires_at', 'oauth_connected_at', 'oauth_scopes'] as $settingKey) {
            $value = $this->setting("usps.{$settingKey}");
            if ($value) {
                $jsonKey = match ($settingKey) {
                    'oauth_token_expires_at' => 'token_expires_at',
                    'oauth_refresh_token_expires_at' => 'refresh_token_expires_at',
                    'oauth_connected_at' => 'connected_at',
                    'oauth_scopes' => 'scopes',
                };
                $secrets[$jsonKey] = $value;
            }
        }
        $account->secret_credentials = $secrets ?: null;
        $account->save();

        $this->ensureScope($account, $carrier->id);

        $this->deleteSettings([
            'usps.oauth_access_token',
            'usps.oauth_refresh_token',
            'usps.oauth_token_expires_at',
            'usps.oauth_refresh_token_expires_at',
            'usps.oauth_connected_at',
            'usps.oauth_scopes',
            'usps.auth_mode',
            'usps.client_id',
            'usps.client_secret',
            'usps.eps_account',
        ]);
        // usps.crid and usps.mid intentionally left as global fallback for USPSConnector.
    }

    private function migrateFedex(): void
    {
        $carrier = Carrier::where('name', 'FedEx')->first();
        if (! $carrier) {
            return;
        }

        $childKey = $this->setting('fedex.child_key');
        $childSecret = $this->setting('fedex.child_secret');
        $childEnv = $this->setting('fedex.child_env') ?? 'production';
        $accountNumber = $this->setting('fedex.account_number');
        $apiKey = $this->setting('fedex.api_key');
        $apiSecret = $this->setting('fedex.api_secret');
        $sandboxApiKey = $this->setting('fedex.sandbox_api_key');
        $sandboxApiSecret = $this->setting('fedex.sandbox_api_secret');

        if (! $childKey && ! $accountNumber && ! $apiKey) {
            return;
        }

        $account = CarrierAccount::where('carrier_id', $carrier->id)->first()
            ?? new CarrierAccount(['carrier_id' => $carrier->id, 'name' => 'FedEx Default', 'active' => true]);

        $credentials = $account->credentials ?? [];
        if ($accountNumber) {
            $credentials['account_number'] = $accountNumber;
        }
        if ($childEnv) {
            $credentials['child_env'] = $childEnv;
        }
        $account->credentials = $credentials ?: null;

        $secrets = $account->secret_credentials ?? [];
        foreach (compact('childKey', 'childSecret', 'apiKey', 'apiSecret', 'sandboxApiKey', 'sandboxApiSecret') as $var => $value) {
            if ($value) {
                $key = (string) preg_replace_callback('/[A-Z]/', fn ($m) => '_'.strtolower($m[0]), $var);
                $secrets[$key] = $value;
            }
        }
        $account->secret_credentials = $secrets ?: null;
        $account->save();

        $this->ensureScope($account, $carrier->id);

        $this->deleteSettings([
            'fedex.child_key',
            'fedex.child_secret',
            'fedex.child_env',
            'fedex.account_number',
            'fedex.api_key',
            'fedex.api_secret',
            'fedex.sandbox_api_key',
            'fedex.sandbox_api_secret',
        ]);
    }

    private function migrateUps(): void
    {
        $carrier = Carrier::where('name', 'UPS')->first();
        if (! $carrier) {
            return;
        }

        $oauthToken = $this->setting('ups.oauth_access_token');
        $refreshToken = $this->setting('ups.oauth_refresh_token');
        $clientId = $this->setting('ups.client_id');
        $clientSecret = $this->setting('ups.client_secret');
        $accountNumber = $this->setting('ups.account_number');

        if (! $oauthToken && ! $clientId && ! $accountNumber) {
            return;
        }

        $account = CarrierAccount::where('carrier_id', $carrier->id)->first()
            ?? new CarrierAccount(['carrier_id' => $carrier->id, 'name' => 'UPS Default', 'active' => true]);

        $credentials = $account->credentials ?? [];
        if ($accountNumber) {
            $credentials['account_number'] = $accountNumber;
        }
        $account->credentials = $credentials ?: null;

        $secrets = $account->secret_credentials ?? [];
        if ($oauthToken) {
            $secrets['oauth_token'] = $oauthToken;
            $secrets['auth_mode'] = 'authorization_code';
        }
        if ($refreshToken) {
            $secrets['refresh_token'] = $refreshToken;
        }
        if ($clientId) {
            $secrets['client_id'] = $clientId;
        }
        if ($clientSecret) {
            $secrets['client_secret'] = $clientSecret;
        }
        foreach (['oauth_token_expires_at', 'oauth_refresh_token_expires_at', 'oauth_connected_at'] as $settingKey) {
            $value = $this->setting("ups.{$settingKey}");
            if ($value) {
                $jsonKey = match ($settingKey) {
                    'oauth_token_expires_at' => 'token_expires_at',
                    'oauth_refresh_token_expires_at' => 'refresh_token_expires_at',
                    'oauth_connected_at' => 'connected_at',
                };
                $secrets[$jsonKey] = $value;
            }
        }
        $account->secret_credentials = $secrets ?: null;
        $account->save();

        $this->ensureScope($account, $carrier->id);

        $this->deleteSettings([
            'ups.oauth_access_token',
            'ups.oauth_refresh_token',
            'ups.oauth_token_expires_at',
            'ups.oauth_refresh_token_expires_at',
            'ups.oauth_connected_at',
            'ups.auth_mode',
            'ups.client_id',
            'ups.client_secret',
            'ups.account_number',
        ]);
    }
};
