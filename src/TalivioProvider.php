<?php

namespace Talivio\Sdk;

use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\ProviderInterface;
use Laravel\Socialite\Two\User;

/**
 * A Socialite driver for "Talivio Accounts" — talivio.com's OAuth2 hub.
 * Behaves exactly like Socialite's built-in Google/GitHub drivers.
 */
class TalivioProvider extends AbstractProvider implements ProviderInterface
{
    protected $scopes = [];

    protected function getAuthUrl($state): string
    {
        return $this->buildAuthUrlFromBase(config('talivio.hub_url').'/oauth/authorize', $state);
    }

    protected function getTokenUrl(): string
    {
        return config('talivio.hub_url').'/oauth/token';
    }

    protected function getUserByToken($token): array
    {
        $response = $this->getHttpClient()->get(config('talivio.hub_url').'/account/api/userinfo', [
            'headers' => ['Authorization' => 'Bearer '.$token],
        ]);

        return json_decode((string) $response->getBody(), true);
    }

    protected function mapUserToObject(array $user): User
    {
        return (new User)->setRaw($user)->map([
            'id' => $user['talivio_id'] ?? $user['sub'],
            'name' => $user['name'] ?? null,
            'email' => $user['email'] ?? null,
            'avatar' => $user['avatar'] ?? null,
        ]);
    }

    protected function getCodeFields($state = null): array
    {
        return array_merge(parent::getCodeFields($state), [
            'code_challenge' => $this->getCodeChallenge(),
            'code_challenge_method' => 'S256',
        ]);
    }

    protected function getCodeChallenge(): string
    {
        $verifier = $this->request->session()->get('talivio_code_verifier');

        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    public function getAccessTokenResponse($code)
    {
        $verifier = $this->request->session()->pull('talivio_code_verifier');

        $response = $this->getHttpClient()->post($this->getTokenUrl(), [
            'headers' => ['Accept' => 'application/json'],
            'form_params' => [
                'grant_type' => 'authorization_code',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'redirect_uri' => $this->redirectUrl,
                'code' => $code,
                'code_verifier' => $verifier,
            ],
        ]);

        return json_decode((string) $response->getBody(), true);
    }

    public function redirect()
    {
        $verifier = strtr(base64_encode(random_bytes(64)), '+/', '-_');
        $verifier = rtrim(substr($verifier, 0, 128), '=');
        $this->request->session()->put('talivio_code_verifier', $verifier);

        return parent::redirect();
    }
}
