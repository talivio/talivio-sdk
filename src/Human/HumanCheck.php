<?php

namespace Talivio\Sdk\Human;

use Illuminate\Support\Facades\Cache;

/**
 * Üçüncü partisiz (Google/Cloudflare'siz) davranışsal insan doğrulaması.
 *
 * Nasıl çalışır: kayıt formu render edilirken `issue()` imzalı, oturuma bağlı
 * bir jeton üretir; sayfadaki toplayıcı (human-check bileşeni) fare/dokunma/
 * klavye etkileşimlerini YALNIZCA toplam sayaç ve varyans olarak özetler —
 * koordinat akışı, tuş içeriği veya parmak izi GÖNDERİLMEZ (GDPR: davranış
 * özeti anlıktır, hiçbir yerde saklanmaz). `verify()` jetonu doğrular ve
 * sinyalleri puanlar.
 *
 * Tehdit modeli: hedef, formu curl/httpx ile dolduran veya başsız tarayıcıyla
 * anında submit eden EMTİA botlarıdır. Bu siteye özel yazılmış, gerçek insan
 * hareketi taklit eden hedefli bir saldırganı davranış sinyali durduramaz —
 * o katman için hız sınırı + e-posta doğrulama zaten ayrıca var.
 *
 * Puanlama pointer-tipine duyarlıdır: dokunmatik cihazda fare sinyali
 * beklenmez (mobil çözüm budur), klavye-only gezinen erişilebilirlik
 * kullanıcısı da yalnız tuş/odak sinyaliyle eşiği geçebilir.
 */
class HumanCheck
{
    /** Bir davranış payload'ının kabul edilebilir azami boyutu (byte). */
    private const MAX_PAYLOAD_BYTES = 4096;

    /** @var string|null Satır içine basılan toplayıcı — istek başına bir kez okunur. */
    private static ?string $collector = null;

    /**
     * Blade bileşeninin satır içine bastığı toplayıcı JS'i.
     *
     * Kaynak dosya ESM sarmalayıcısı DEĞİL, çekirdektir (`talivio-human-core.js`):
     * o dosya bilinçli olarak `import`/`export` içermez, çünkü aynı metin hem
     * klasik `<script>` etiketinde hem bundler içinde çalışmak zorunda. Böylece
     * Blade ve SPA yolları tek bir uygulamayı paylaşır.
     */
    public static function collectorScript(): string
    {
        return self::$collector ??= (string) file_get_contents(__DIR__.'/../../resources/js/talivio-human-core.js');
    }

    /**
     * İmzalı jeton üretir: base64url(json{iat,nonce,sid}).hex_hmac.
     *
     * Oturum kimliği hash'lenerek jetona bağlanır — bir kurbanın sayfasından
     * sızan jeton başka bir oturumun POST'unda geçmez. İmza uygulama
     * anahtarıyla atılır; sunucu tarafında durum tutulmaz (yalnız tek-kullanım
     * işareti cache'e düşer).
     */
    public function issue(): string
    {
        $claims = json_encode([
            'iat' => now()->getTimestamp(),
            'nonce' => bin2hex(random_bytes(8)),
            'sid' => $this->sessionHash(),
        ]);

        $body = $this->base64UrlEncode($claims);

        return $body.'.'.hash_hmac('sha256', $body, $this->key());
    }

    /**
     * Formdan gelen davranış payload'ını değerlendirir.
     *
     * @param mixed $payload `talivio_human` alanının ham değeri
     * @param string|null $honeypot Görünmez tuzak alanının değeri (dolu = bot)
     */
    public function verify(mixed $payload, ?string $honeypot = null): HumanVerdict
    {
        if (! is_string($payload) || $payload === '') {
            // JS hiç çalışmamış: ya script'siz bir bot ya da JS'i kapalı,
            // eşiği geçemeyecek bir istemci.
            return HumanVerdict::fail(['missing_payload']);
        }

        if (strlen($payload) > self::MAX_PAYLOAD_BYTES) {
            return HumanVerdict::fail(['oversized_payload']);
        }

        if (is_string($honeypot) && trim($honeypot) !== '') {
            return HumanVerdict::fail(['honeypot']);
        }

        $data = json_decode($payload, true);

        if (! is_array($data)) {
            return HumanVerdict::fail(['bad_json']);
        }

        $claims = $this->validateToken($data['tok'] ?? null);

        if (is_string($claims)) {
            return HumanVerdict::fail([$claims]);
        }

        $age = now()->getTimestamp() - $claims['iat'];

        if ($age < (int) config('talivio.human.min_seconds')) {
            // İmzalı iat esas alınır — istemcinin bildirdiği süreye güvenilmez.
            return HumanVerdict::fail(['too_fast']);
        }

        if (($this->intSignal($data, 'wd')) === 1) {
            return HumanVerdict::fail(['webdriver']);
        }

        $score = $this->score($data);

        if ($score < (int) config('talivio.human.min_score')) {
            return HumanVerdict::fail(['low_score'], $score);
        }

        return HumanVerdict::pass($score);
    }

    /**
     * Jetonu tek kullanımlık işaretler — YALNIZCA kayıt gerçekten tamamlanınca.
     *
     * ⚠️ Bunun `verify()` içinde yapılması gerçek bir hataydı ve tarayıcıda
     * yakalandı: formun BAŞKA bir alanı hatalıysa (boş mağaza adı, şifre
     * uyuşmazlığı, kullanılmış e-posta) jeton yine de yanıyordu; kullanıcı
     * hatayı düzeltip tekrar gönderdiğinde "insan olduğunuzu doğrulayamadık"
     * duvarına çarpıyor ve sayfayı yenilemeden ilerleyemiyordu. Yani koruma,
     * botları değil hata yapan gerçek kullanıcıları kilitliyordu.
     *
     * @return bool false = jeton daha önce kullanılmış (tekrar oynatma)
     */
    public function consume(mixed $payload): bool
    {
        if (! is_string($payload) || $payload === '') {
            return false;
        }

        $data = json_decode($payload, true);
        $token = is_array($data) ? ($data['tok'] ?? null) : null;

        if (! is_string($token)) {
            return false;
        }

        $claims = $this->validateToken($token);

        if (is_string($claims)) {
            return false;
        }

        return $this->consumeOnce($token, now()->getTimestamp() - $claims['iat']);
    }

    /**
     * Sinyalleri puanlar. Eşikler emtia botlarının tipik "sıfır veya sabit"
     * profiline göre seçildi; her gerçekçi insan akışı (masaüstü yazan,
     * masaüstü autofill, mobil yazan, mobil autofill, klavye-only) en az
     * min_score=3 toplayabilecek şekilde yollar çakıştırıldı.
     *
     * @param array<string, mixed> $data
     */
    private function score(array $data): int
    {
        $s = fn (string $key): int => $this->intSignal($data, $key);
        $score = 0;

        // Fare: yeterli örneklem + mesafe. Yön değişimi (ma) ayrı puanlanır —
        // sentetik doğrusal hareket mesafe biriktirir ama yön değiştirmez.
        if ($s('mm') >= 10 && $s('md') >= 300) {
            $score += 2;
        }

        if ($s('ma') >= 6) {
            $score += 1;
        }

        // Dokunma: birden fazla ayrı dokunuş (alanlara/onay kutusuna/karta
        // dokunmak) + kaydırma hareketi. Mobilin fare karşılığı budur.
        if ($s('ts') >= 2) {
            $score += 2;
        }

        if ($s('tc') >= 5) {
            $score += 1;
        }

        // İvmeölçer gürültüsü: elde tutulan cihaz asla tam sabit değildir.
        // (iOS 13+ izinsiz vermez — sinyal yoksa ceza da yok.)
        if ($s('dm') >= 3) {
            $score += 1;
        }

        // Klavye: kayıt formunu doldurmak gerçekçi olarak ≥12 tuş ister;
        // insan tuş aralıklarının varyansı yüksektir (sabit aralık = script).
        if ($s('ky') >= 12) {
            $score += 1;
        }

        if ($s('kv') >= 900) {
            $score += 1;
        }

        if ($s('fo') >= 2) {
            $score += 1;
        }

        if ($s('cl') >= 1) {
            $score += 1;
        }

        if ($s('sc') >= 3) {
            $score += 1;
        }

        return $score;
    }

    /**
     * Jetonu çözer ve doğrular; hata durumunda sebep string'i döndürür.
     *
     * @return array{iat: int, nonce: string, sid: string}|string
     */
    private function validateToken(mixed $token): array|string
    {
        if (! is_string($token) || substr_count($token, '.') !== 1) {
            return 'bad_token';
        }

        [$body, $sig] = explode('.', $token, 2);

        if (! hash_equals(hash_hmac('sha256', $body, $this->key()), $sig)) {
            return 'bad_signature';
        }

        $claims = json_decode($this->base64UrlDecode($body), true);

        if (! is_array($claims) || ! is_int($claims['iat'] ?? null) || ! is_string($claims['nonce'] ?? null)) {
            return 'bad_claims';
        }

        $age = now()->getTimestamp() - $claims['iat'];

        if ($age < 0 || $age > (int) config('talivio.human.token_ttl')) {
            return 'expired';
        }

        /*
         * Jeton, üretildiği oturuma bağlıdır: sızdırılan bir jeton başka bir
         * oturumun gönderiminde geçmez.
         *
         * ⚠️ Oturum kimliği ŞU ANKİ bağlamda hiç bulunamıyorsa bağ SESSİZCE
         * ATLANIR, "eşleşmedi" sayılmaz. Gerekçesi bir başarısızlık modunun
         * asimetrisi: bu kontrolün gereksiz yere geçmesi korumayı bir kademe
         * zayıflatır (jeton hâlâ imzalı, süreli, tek kullanımlık ve davranış
         * puanı istiyor), ama gereksiz yere KALMASI ürünün bütün kayıtlarını
         * hiçbir uyarı vermeden reddeder. Saldırgan sunucuyu oturumsuz bir
         * bağlama zorlayamaz — web istekleri her zaman oturum taşır.
         */
        $currentSession = $this->sessionHash();

        if ($currentSession !== '' && ($claims['sid'] ?? '') !== $currentSession) {
            return 'session_mismatch';
        }

        return $claims;
    }

    /**
     * Jetonu tek kullanımlık yapar: aynı jeton (ve dolayısıyla aynı kayıtlı
     * davranış özeti) TTL içinde ikinci bir kayıtta tekrar kullanılamaz.
     */
    private function consumeOnce(string $token, int $age): bool
    {
        $remaining = max(60, (int) config('talivio.human.token_ttl') - $age);

        return Cache::add('talivio:human:'.hash('sha256', $token), 1, $remaining);
    }

    /**
     * Jetonu oturuma bağlayan kimlik.
     *
     * ⚠️ Önce istek nesnesine, o taşımıyorsa GLOBAL oturum deposuna bakılır.
     * Yalnızca `request()->hasSession()`'a güvenmek sessiz bir felaket
     * kapısıydı: oturumu isteğe bağlamayan bir bağlamda (Livewire'ın kendi
     * istek nesnesi, testte böyle davranıyor) jeton bir tarafta gerçek
     * oturumla, diğer tarafta boş kimlikle hesaplanır ve ürünün TÜM kayıtları
     * "insan değilsiniz" ile reddedilirdi — hiçbir uyarı vermeden.
     *
     * Oturum hiç başlamamışsa (konsol, kuyruk) iki taraf da boş döner ve bağ
     * devre dışı kalır; bu bilinçli: orada zaten oturum kavramı yoktur.
     */
    private function sessionHash(): string
    {
        $request = request();

        if ($request->hasSession()) {
            return hash('sha256', $request->session()->getId());
        }

        if (app()->bound('session.store')) {
            // `isStarted()` ARANMAZ: istek tamamlandıktan sonra depo kaydedilip
            // "başlamamış" duruma döner ama kimliği korur — aradığımız da tam
            // olarak o kimliktir.
            $id = app('session.store')->getId();

            if (is_string($id) && $id !== '') {
                return hash('sha256', $id);
            }
        }

        return '';
    }

    /** @param array<string, mixed> $data */
    private function intSignal(array $data, string $key): int
    {
        $value = $data[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    private function key(): string
    {
        $key = (string) config('app.key');

        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7));
        }

        return $key;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'));
    }
}
