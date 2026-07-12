<?php

namespace Talivio\Sdk\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

class SupportFormController extends Controller
{
    public function store(Request $request)
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

        $response = Http::withToken(config('talivio.ingest_token'))
            ->timeout(5)
            ->post(rtrim(config('talivio.hub_url'), '/').'/api/ingest/tickets', $data);

        if ($response->failed()) {
            return back()->withErrors(['message' => 'Destek talebiniz gönderilemedi, lütfen tekrar deneyin.']);
        }

        return back()->with('status', 'support-ticket-sent');
    }
}
