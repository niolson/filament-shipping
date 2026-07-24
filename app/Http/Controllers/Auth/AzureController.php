<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OAuthService;
use App\Services\SettingsService;
use App\Services\SsoLoginService;
use Illuminate\Http\RedirectResponse;
use Laravel\Socialite\Facades\Socialite;
use SocialiteProviders\Azure\User as AzureUser;

class AzureController extends Controller
{
    public function redirect(): RedirectResponse
    {
        if (! app(SettingsService::class)->get('azure_sso_enabled', false)) {
            return redirect('/')->with('error', 'Azure SSO is not enabled.');
        }

        if ($this->shouldUseBroker()) {
            if (! $this->brokerConfigured()) {
                logger()->error('Azure SSO is set to use the OAuth broker but broker_secret or instance_id is missing.');

                return redirect('/login')->withErrors([
                    'data.login' => 'Single sign-on is not configured correctly. Contact your admin.',
                ]);
            }

            return redirect()->away(app(OAuthService::class)->initiateSsoAuthorization('azure'));
        }

        return Socialite::driver('azure')->redirect();
    }

    public function callback(): RedirectResponse
    {
        if (! app(SettingsService::class)->get('azure_sso_enabled', false)) {
            return redirect('/')->with('error', 'Azure SSO is not enabled.');
        }

        try {
            $azureUser = Socialite::driver('azure')->user();
        } catch (\Exception $e) {
            logger()->error('Azure SSO callback failed', ['error' => $e->getMessage()]);

            return redirect('/login')->with('error', 'Azure authentication failed. Please try again.');
        }

        // Azure maps getEmail() to userPrincipalName, which can differ from the
        // actual mailbox address; prefer the mail attribute when present.
        $email = $azureUser instanceof AzureUser
            ? ($azureUser->getMail() ?: $azureUser->getEmail())
            : $azureUser->getEmail();

        $user = User::where('email', $email)->first();

        if (! $user) {
            logger()->warning('SSO login rejected: no account for email returned by Azure', [
                'email' => $email,
            ]);

            return redirect('/login')->withErrors([
                'data.login' => 'No account found for this email. Contact your admin.',
            ]);
        }

        if (! $user->active) {
            return redirect('/login')->withErrors([
                'data.login' => 'This account is disabled. Contact your admin.',
            ]);
        }

        // Direct Socialite path: the app can't verify the IdP's `amr` the way the
        // broker does, so pass no claims — this always falls through to the app's
        // own MFA challenge (fail closed). IdP-MFA trust rides only on the broker's
        // verified ID-token claims (see SsoCallbackController).
        return app(SsoLoginService::class)->completeLogin($user, 'azure');
    }

    private function shouldUseBroker(): bool
    {
        return filled(config('services.oauth.broker_url'))
            && ! config('services.oauth.bypass_broker', false);
    }

    private function brokerConfigured(): bool
    {
        return filled(config('services.oauth.broker_url'))
            && filled(config('services.oauth.broker_secret'))
            && filled(config('services.oauth.instance_id'));
    }
}
