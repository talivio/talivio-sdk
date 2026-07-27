<?php

namespace Talivio\Sdk\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * GDPR deletion propagation — the Talivio Accounts hub calls this endpoint
 * when a user permanently deletes their Talivio account, so the local user
 * this product created for them is erased too.
 *
 * Requests are authenticated with an HMAC-SHA256 signature over
 * "{timestamp}.{raw body}" using the app's ingest token as the shared
 * secret — the same secret this product already holds for telemetry.
 */
class AccountDeletionController extends Controller
{
    private const TIMESTAMP_TOLERANCE_SECONDS = 300;

    public function __invoke(Request $request)
    {
        /*
         * İki anahtar da kabul edilir ve bu GEÇİŞ İÇİN bilinçli: hub artık
         * ürün başına ayrı bir `webhook_secret` üretiyor, ama tüm ürünler
         * güncellenip .env'lerine o değeri alana kadar eski `ingest_token`
         * imzası geçerli kalmalı. Aksi hâlde SDK'yı güncelleyen ilk ürün
         * silme çağrılarını reddetmeye başlardı. Her iki anahtar da
         * denendiği için sıra bağımsız güncelleme mümkün.
         */
        $secrets = array_values(array_filter([
            config('talivio.webhook_secret'),
            config('talivio.ingest_token'),
        ]));

        if ($secrets === []) {
            return response()->json(['status' => 'not_configured'], 503);
        }

        $timestamp = $request->header('X-Talivio-Timestamp');
        $signature = $request->header('X-Talivio-Signature');

        if (! $timestamp || ! $signature) {
            return response()->json(['status' => 'unauthorized'], 401);
        }

        if (abs(now()->timestamp - (int) $timestamp) > self::TIMESTAMP_TOLERANCE_SECONDS) {
            return response()->json(['status' => 'stale_timestamp'], 401);
        }

        $signed = $timestamp.'.'.$request->getContent();
        $matched = false;

        foreach ($secrets as $secret) {
            // hash_equals her aday için çağrılır (erken çıkış yok) ki
            // doğrulama süresi hangi anahtarın tuttuğunu sızdırmasın.
            $matched = hash_equals(hash_hmac('sha256', $signed, $secret), $signature) || $matched;
        }

        if (! $matched) {
            return response()->json(['status' => 'unauthorized'], 401);
        }

        $talivioId = $request->json('talivio_id');

        if (! $talivioId) {
            return response()->json(['status' => 'invalid_payload'], 422);
        }

        $userModel = config('talivio.user_model');
        $column = config('talivio.talivio_id_column');

        $user = $userModel::where($column, $talivioId)->first();

        // Idempotent: the hub may retry, or this product may never have
        // created a local account for that Talivio ID.
        if (! $user) {
            return response()->json(['status' => 'not_found']);
        }

        try {
            if (config('talivio.deletion_behavior', 'delete') === 'unlink') {
                // Keep the local account (it may own business records) but
                // sever the SSO link and forget the Talivio identity.
                $user->{$column} = null;
                $user->save();

                Log::info('Talivio SDK: unlinked local account after Talivio Accounts deletion', [
                    'user_id' => $user->getKey(),
                ]);

                return response()->json(['status' => 'unlinked']);
            }

            $user->delete();

            Log::info('Talivio SDK: deleted local account after Talivio Accounts deletion', [
                'user_id' => $user->getKey(),
            ]);

            return response()->json(['status' => 'deleted']);
        } catch (Throwable $e) {
            Log::error('Talivio SDK: failed to process Talivio Accounts deletion', [
                'talivio_id' => $talivioId,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['status' => 'error'], 500);
        }
    }
}
