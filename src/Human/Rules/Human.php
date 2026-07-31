<?php

namespace Talivio\Sdk\Human\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Validator;
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
class Human implements ValidationRule, ValidatorAwareRule
{
    /** Alan istekte yokken de kuralın koşmasını sağlar. */
    public bool $implicit = true;

    protected ?Validator $validator = null;

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

    public function setValidator($validator): static
    {
        $this->validator = $validator;

        return $this;
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

        $humanCheck = app(HumanCheck::class);

        $verdict = $humanCheck->verify(
            $value,
            $this->honeypot ?? request()->input('tl_website'),
        );

        if (! $verdict->passed) {
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

            return;
        }

        $this->consumeWhenValidationSucceeds($attribute, $value, $fail, $humanCheck);
    }

    /**
     * Tek kullanımlık işaretini, formun TAMAMI geçerliyse koyar.
     *
     * ⚠️ Neden `after`: jetonu burada hemen tüketmek, formun başka bir alanı
     * hatalı olduğunda (boş alan, şifre uyuşmazlığı, kullanılmış e-posta)
     * kullanıcının düzeltip tekrar göndermesini imkânsız kılıyordu — ikinci
     * gönderim "tekrar oynatma" sayılıp reddediliyordu. Bu, botları değil
     * hata yapan gerçek kullanıcıları kilitleyen bir hataydı (tarayıcıda
     * yakalandı). `after` geri çağrısı tüm kurallar koştuktan sonra çalışır,
     * bu yüzden burada hata torbası boşsa kayıt gerçekten tamamlanacak
     * demektir.
     */
    private function consumeWhenValidationSucceeds(string $attribute, mixed $value, Closure $fail, HumanCheck $humanCheck): void
    {
        if (! $this->validator) {
            // Kural doğrudan (Validator dışında) kullanılmış — tekrar oynatma
            // korumasını sessizce kaybetmemek için hemen tüket.
            $humanCheck->consume($value);

            return;
        }

        $this->validator->after(function (Validator $validator) use ($attribute, $value, $humanCheck) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($humanCheck->consume($value)) {
                return;
            }

            Log::warning('talivio.human: kayıt denemesi insan doğrulamasını geçemedi', [
                'reasons' => ['replayed'],
                'ip' => request()->ip(),
                'log_only' => (bool) config('talivio.human.log_only'),
            ]);

            if (config('talivio.human.log_only')) {
                return;
            }

            $validator->errors()->add($attribute, __('talivio::human.failed'));
        });
    }
}
