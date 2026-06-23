<?php

use App\Models\CarrierAccount;
use App\Models\DataSource;
use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Swap the active encrypter so the test can simulate an APP_KEY rotation:
 * a current key plus an optional list of previous (fallback) keys.
 *
 * @param  list<string>  $previous
 */
function useKeys(string $current, array $previous = []): void
{
    $encrypter = new Encrypter($current, config('app.cipher'));

    if ($previous !== []) {
        $encrypter->previousKeys($previous);
    }

    Crypt::swap($encrypter);
}

it('re-encrypts secrets so they are readable with the new key alone', function () {
    $cipher = config('app.cipher');
    $keyA = Encrypter::generateKey($cipher);
    $keyB = Encrypter::generateKey($cipher);

    // Encrypt data with key A.
    useKeys($keyA);
    $carrier = CarrierAccount::factory()->create(['secret_credentials' => ['token' => 'carrier-secret']]);
    $source = DataSource::factory()->create(['secret_settings' => ['api_key' => 'source-secret']]);
    // Insert a properly-encrypted setting row directly (deterministic — avoids the
    // Setting mutator's attribute-ordering sensitivity).
    DB::table('settings')->insert([
        'key' => 'oauth.token',
        'value' => Crypt::encryptString('tok-123'),
        'type' => 'string',
        'encrypted' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Rotate to key B, keeping A as the fallback — existing data still decrypts.
    useKeys($keyB, [$keyA]);
    expect(CarrierAccount::find($carrier->id)->secret_credentials)->toBe(['token' => 'carrier-secret']);

    // Control: with key B alone, data still encrypted under A must fail to decrypt.
    useKeys($keyB);
    expect(fn () => CarrierAccount::find($carrier->id)->secret_credentials)
        ->toThrow(DecryptException::class);

    // Re-encrypt while both keys are available.
    useKeys($keyB, [$keyA]);
    $this->artisan('app:reencrypt-secrets')->assertSuccessful();

    // Key B alone now suffices for every store — the previous key can be retired.
    useKeys($keyB);
    expect(CarrierAccount::find($carrier->id)->secret_credentials)->toBe(['token' => 'carrier-secret']);
    expect(DataSource::find($source->id)->secret_settings)->toBe(['api_key' => 'source-secret']);
    expect(Setting::find('oauth.token')->value)->toBe('tok-123');
});

it('leaves unencrypted settings untouched', function () {
    $cipher = config('app.cipher');
    $keyA = Encrypter::generateKey($cipher);
    $keyB = Encrypter::generateKey($cipher);

    useKeys($keyA);
    Setting::create(['key' => 'app.name', 'value' => 'PolyBag', 'type' => 'string', 'encrypted' => false]);

    useKeys($keyB, [$keyA]);
    $this->artisan('app:reencrypt-secrets')->assertSuccessful();

    // Plaintext setting is still stored verbatim (not double-encrypted).
    expect(DB::table('settings')->where('key', 'app.name')->value('value'))
        ->toBe('PolyBag');
});

it('does not write anything on a dry run', function () {
    $cipher = config('app.cipher');
    $keyA = Encrypter::generateKey($cipher);
    $keyB = Encrypter::generateKey($cipher);

    useKeys($keyA);
    $carrier = CarrierAccount::factory()->create(['secret_credentials' => ['token' => 'carrier-secret']]);
    $cipherBefore = DB::table('carrier_accounts')->where('id', $carrier->id)->value('secret_credentials');

    useKeys($keyB, [$keyA]);
    $this->artisan('app:reencrypt-secrets', ['--dry-run' => true])->assertSuccessful();

    $cipherAfter = DB::table('carrier_accounts')->where('id', $carrier->id)->value('secret_credentials');
    expect($cipherAfter)->toBe($cipherBefore);
});
