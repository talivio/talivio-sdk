<?php

namespace Talivio\Sdk\Human\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Log;
use Talivio\Sdk\Human\HumanCheck;

/**
 * Kayıt (veya benzeri) formlara eklenen davranışsal insan doğrulama kuralı.
 *
 * Kullanım — view'da `<x-talivio::human-check />` bileşeninin yanına:
 *
 *     'talivio_human' => [new \Talivio\Sdk\Human\Rules\Human],
 *
 * Alan hiç gönderilmese de çalışır ($implicit): payload'ı olmayan istek
 * (script'siz bot) sessizce geçemez.
 */
class Human implements ValidationRule
{
    /** Alan istekte yokken de kuralın koşmasını sağlar. */
    public bool $implicit = true;

    /**
     * @param string|null $honeypot Tuzak alanın değeri. Klasik formlarda ve
     *   Inertia'da boş bırakılır — kural onu istekten (`tl_website`) okur.
     *   LIVEWIRE'da mutlaka verilmelidir (`new Human($this->tl_website)`):
     *   Livewire isteğinde alanlar bileşen payload'ının içinde taşınır, düz
     *   `tl_website` girdisi olarak GELMEZ ve tuzak sessizce ölü kalırdı.
     */
    public function __construct(private readonly ?string $honeypot = null)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! config('talivio.human.enabled')) {
            return;
        }

        // Ürünlerin mevcut kayıt testleri davranış payload'ı üretemez; test
        // ortamında kural, açıkça istenmedikçe (enforce_in_tests) devre dışıdır.
        if (app()->runningUnitTests() && ! config('talivio.human.enforce_in_tests')) {
            return;
        }

        $verdict = app(HumanCheck::class)->verify(
            $value,
            $this->honeypot ?? request()->input('tl_website'),
        );

        if ($verdict->passed) {
            return;
        }

        Log::warning('talivio.human: kayıt denemesi insan doğrulamasını geçemedi', [
            'reasons' => $verdict->reasons,
            'score' => $verdict->score,
            'ip' => request()->ip(),
            'log_only' => (bool) config('talivio.human.log_only'),
        ]);

        // Gölge mod: karar loglanır ama kayıt engellenmez — canlıda eşikleri
        // gözlemleyerek açmak için.
        if (config('talivio.human.log_only')) {
            return;
        }

        $fail('talivio::human.failed')->translate();
    }
}
