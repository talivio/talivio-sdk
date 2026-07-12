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

    /**
     * "Talivio Accounts'a bağla" — for an already-authenticated user linking
     * their existing local account, from a profile/settings page rather than
     * the login screen.
     */
    public function link(Request $request)
    {
        $request->session()->put('talivio.linking', true);

        return Socialite::driver('talivio')
            ->redirectUrl(config('talivio.redirect') ?: url('/talivio/callback'))
            ->redirect();
    }

    public function unlink(Request $request)
    {
        $user = $request->user(config('talivio.guard'));
        $talivioIdColumn = config('talivio.talivio_id_column');

        $user->{$talivioIdColumn} = null;
        $user->save();

        return back()->with('talivio_status', 'unlinked');
    }

    public function callback(Request $request)
    {
        $linking = $request->session()->pull('talivio.linking', false);

        if ($request->has('error')) {
            if ($linking) {
                return redirect(config('talivio.login_redirect'))
                    ->with('talivio_status', 'link-failed');
            }

            return redirect(config('talivio.login_redirect'))
                ->withErrors(['talivio' => 'Talivio Accounts girişi iptal edildi veya başarısız oldu.']);
        }

        $talivioUser = Socialite::driver('talivio')
            ->redirectUrl(config('talivio.redirect') ?: url('/talivio/callback'))
            ->user();

        $userModel = config('talivio.user_model');
        $talivioIdColumn = config('talivio.talivio_id_column');

        if ($linking) {
            return $this->handleLink($request, $talivioUser, $userModel, $talivioIdColumn);
        }

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

    private function handleLink(Request $request, $talivioUser, string $userModel, string $talivioIdColumn)
    {
        $currentUser = $request->user(config('talivio.guard'));

        if (! $currentUser) {
            return redirect(config('talivio.login_redirect'))
                ->with('talivio_status', 'link-failed');
        }

        $alreadyLinkedTo = $userModel::where($talivioIdColumn, $talivioUser->getId())->first();

        if ($alreadyLinkedTo && $alreadyLinkedTo->getKey() !== $currentUser->getKey()) {
            return back()->with('talivio_status', 'link-conflict');
        }

        $currentUser->{$talivioIdColumn} = $talivioUser->getId();
        $currentUser->save();

        return redirect(config('talivio.login_redirect'))->with('talivio_status', 'linked');
    }
}
