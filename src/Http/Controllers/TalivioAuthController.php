<?php

namespace Talivio\Sdk\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

/**
 * "Talivio Accounts ile devam et" — redirects to talivio.com/oauth/authorize
 * and, on callback, finds-or-creates this app's own local user, matching the
 * same behaviour Google gives its own first-party apps.
 */
class TalivioAuthController extends Controller
{
    public function redirect()
    {
        return Socialite::driver('talivio')
            ->redirectUrl(config('talivio.redirect') ?: url('/talivio/callback'))
            ->redirect();
    }

    public function callback(Request $request)
    {
        if ($request->has('error')) {
            return redirect(config('talivio.login_redirect'))
                ->withErrors(['talivio' => 'Talivio Accounts girişi iptal edildi veya başarısız oldu.']);
        }

        $talivioUser = Socialite::driver('talivio')
            ->redirectUrl(config('talivio.redirect') ?: url('/talivio/callback'))
            ->user();

        $userModel = config('talivio.user_model');
        $talivioIdColumn = config('talivio.talivio_id_column');

        $user = $userModel::where($talivioIdColumn, $talivioUser->getId())->first();

        if (! $user) {
            // Only auto-link to an existing local account when its email is
            // already verified locally — otherwise create a fresh account.
            $existing = $userModel::where('email', $talivioUser->getEmail())->first();

            if ($existing && ($existing->email_verified_at ?? null)) {
                $user = $existing;
                $user->{$talivioIdColumn} = $talivioUser->getId();
            } else {
                $user = new $userModel;
                $user->{$talivioIdColumn} = $talivioUser->getId();
                $user->email = $talivioUser->getEmail();
                $user->email_verified_at = now();
                $user->password = Hash::make(Str::random(40));
            }
        }

        // Keep the local profile in sync with Talivio Accounts on every login.
        $user->name = $talivioUser->getName() ?: $user->name;
        $user->save();

        Auth::guard(config('talivio.guard'))->login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended(config('talivio.login_redirect'));
    }
}
