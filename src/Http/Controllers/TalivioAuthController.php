<?php

namespace Talivio\Sdk\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use RuntimeException;

/**
 * "Talivio Accounts ile devam et" — redirects to talivio.com/oauth/authorize
 * and, on callback, finds-or-creates this app's own local user, matching the
 * same behaviour Google gives its own first-party apps.
 */
class TalivioAuthController extends Controller
{
    public function redirect()
    {
        $this->guardConfigured();

        return Socialite::driver('talivio')
            ->redirectUrl(config('talivio.redirect') ?: url('/talivio/callback'))
            ->redirect();
    }

    /**
     * `client_id`/`client_secret` boş bırakılırsa (Filament'te ürün kaydedilip
     * .env'e kopyalanmadıysa) Socialite bunu sessizce atlar: `client_id`
     * parametresi olmadan bir authorize URL'i üretir, hub tarafı da bunu
     * `invalid_client` ile reddeder. Kullanıcı tıklar, çirkin bir JSON/hata
     * sayfası görür ya da hiçbir şey olmamış gibi hisseder — hiçbir yerde
     * loglanmaz. Burada erken patlatmak, ürünün kendi hata telemetrisi
     * (ErrorReporter, ServiceProvider'da exception handler'a bağlı) üzerinden
     * talivio.com'un "Talivio Ops" panosuna otomatik düşmesini sağlıyor.
     */
    private function guardConfigured(): void
    {
        if (! config('talivio.client_id') || ! config('talivio.client_secret')) {
            throw new RuntimeException(
                'Talivio Accounts yapılandırılmamış: TALIVIO_CLIENT_ID / TALIVIO_CLIENT_SECRET .env\'de eksik. '
                .'Değerler "Talivio Ürünleri" (talivio.com/admin) altında bu ürünün kaydından alınır.'
            );
        }
    }

    /**
     * "Talivio Accounts'a bağla" — for an already-authenticated user linking
     * their existing local account, from a profile/settings page rather than
     * the login screen.
     */
    public function link(Request $request)
    {
        $this->guardConfigured();

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
            // Link to an existing local account by email if one exists —
            // creating a new row here would collide with the email's unique
            // constraint. Talivio Accounts already verified this email, so
            // linking is safe regardless of the local row's prior
            // verification state; only a genuinely new email creates a
            // fresh account.
            $user = $userModel::where('email', $talivioUser->getEmail())->first();

            if (! $user) {
                $user = new $userModel;
                $user->email = $talivioUser->getEmail();
                $user->password = Hash::make(Str::random(40));
            }

            $user->{$talivioIdColumn} = $talivioUser->getId();
            $user->email_verified_at = $user->email_verified_at ?? now();
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
