<?php

namespace Talivio\Sdk\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Talivio\Sdk\Human\HumanCheck;

/**
 * Kendi formunu JS'te kuran ürünler (Inertia/Vue/React) için jeton kaynağı.
 *
 * Blade formlarında jeton doğrudan sayfaya basılır; SPA'da böyle bir render
 * anı yoktur, bu yüzden istemci jetonu buradan ister. Jeton oturuma bağlı ve
 * tek kullanımlıktır — uç noktanın herkese açık olması korumayı zayıflatmaz:
 * bir bot jetonu alabilir ama davranış puanını, asgari süreyi ve tek-kullanım
 * kaydını yine geçmek zorundadır (kayıt sayfasını GET etmekten farkı yoktur).
 */
class HumanTokenController extends Controller
{
    public function __invoke(HumanCheck $humanCheck): JsonResponse
    {
        if (! config('talivio.human.enabled')) {
            return response()->json(['token' => ''], 404);
        }

        return response()->json(['token' => $humanCheck->issue()])
            ->header('Cache-Control', 'no-store');
    }
}
