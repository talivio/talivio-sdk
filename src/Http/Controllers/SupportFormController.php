<?php

namespace Talivio\Sdk\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Talivio\Sdk\Ingest\IngestClient;

class SupportFormController extends Controller
{
    public function store(Request $request, IngestClient $ingest)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
            'priority' => ['nullable', 'in:low,normal,high,urgent'],
        ]);

        $user = Auth::guard(config('talivio.guard'))->user();
        $data['talivio_id'] = $user?->{config('talivio.talivio_id_column')};

        /*
         * ⚠️ Bu kontrolün doğru çalışması IngestClient'ın `acceptJson()`
         * göndermesine bağlı. Başlıksızken hub'ın doğrulama reddi 302'ye
         * dönüşüyor, `failed()` false kalıyor ve kullanıcıya "talebiniz alındı"
         * deniyordu — oysa hub'da hiçbir kayıt oluşmuyordu. Bir destek
         * formunun sessizce yutulması, kullanıcının cevap beklerken ortada
         * kalması demek; telemetrinin aksine bu yol fail-safe DEĞİL, görünür
         * olmalı.
         */
        if (! $ingest->send('tickets', $data)) {
            return back()
                ->withInput()
                ->withErrors(['message' => 'Destek talebiniz gönderilemedi, lütfen tekrar deneyin.']);
        }

        return back()->with('status', 'support-ticket-sent');
    }
}
