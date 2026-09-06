<?php

namespace Talivio\Sdk;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Laravel\Socialite\Facades\Socialite;
use Talivio\Sdk\Ai\CircuitBreaker;
use Talivio\Sdk\Ai\Contracts\DegradationStrategy;
use Talivio\Sdk\Ai\Contracts\TextClient;
use Talivio\Sdk\Ai\Contracts\Transport;
use Talivio\Sdk\Ai\Degradation\DisableFeature;
use Talivio\Sdk\Ai\GatewayTextClient;
use Talivio\Sdk\Ai\Migration\MigrationProbeCommand;
use Talivio\Sdk\Ai\Migration\MigrationReportCommand;
use Talivio\Sdk\Ai\Migration\ShadowTextClient;
use Talivio\Sdk\Ai\TalivioAi;
use Talivio\Sdk\Ai\Transports\FakeTransport;
use Talivio\Sdk\Ai\Transports\HttpTransport;
use Talivio\Sdk\Console\HeartbeatCommand;
use Talivio\Sdk\Http\Controllers\AccountDeletionController;
use Talivio\Sdk\Http\Controllers\HumanTokenController;
use Talivio\Sdk\Http\Controllers\SupportFormController;
use Talivio\Sdk\Http\Controllers\TalivioAuthController;
use Talivio\Sdk\Http\Security\Csp;
use Talivio\Sdk\Human\HumanCheck;
use Talivio\Sdk\Infra\Clients\Cloudflare;
use Talivio\Sdk\Infra\Clients\Mailcow;
use Talivio\Sdk\Infra\Clients\MailioGateway;
use Talivio\Sdk\Infra\Clients\Namecheap;
use Talivio\Sdk\Infra\Clients\Openprovider;
use Talivio\Sdk\Infra\Clients\Ploi;
use Talivio\Sdk\Infra\Contracts\Dns;
use Talivio\Sdk\Infra\Contracts\DnsProbe;
use Talivio\Sdk\Infra\Contracts\Host;
use Talivio\Sdk\Infra\Contracts\Mail;
use Talivio\Sdk\Infra\Contracts\ProductMail;
use Talivio\Sdk\Infra\Contracts\Registrar;
use Talivio\Sdk\Infra\Exceptions\NotConfiguredException;
use Talivio\Sdk\Infra\Support\SystemDnsProbe;
use Talivio\Sdk\Infra\Support\UnconfiguredDns;
use Talivio\Sdk\Infra\Support\UnconfiguredHost;
use Talivio\Sdk\Infra\Support\UnconfiguredMail;
use Talivio\Sdk\Infra\Support\UnconfiguredProductMail;
use Talivio\Sdk\Infra\Support\UnconfiguredRegistrar;
use Talivio\Sdk\Ingest\IngestClient;
use Talivio\Sdk\Ingest\TalivioOps;
use Throwable;

class TalivioServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/talivio.php', 'talivio');

        /*
         * Talivio AI ağ geçidi istemcisi (talivio-ai ROADMAP ADR-02/ADR-03).
         *
         * NEDEN SDK'NIN İÇİNDE: SDK zaten HER Talivio ürününde kurulu ve
         * "merkezî ops" sorumluluğunu taşıyor (SSO + telemetri). AI istemcisi
         * de aynı kategoride — ayrı bir paket, 18 projeye ikinci bir
         * bağımlılık ve ikinci bir sürüm döngüsü eklerdi. Envanterde ölçülen
         * asıl sorun buydu: aynı GeminiClient en az 10 projeye kopyalanmıştı.
         */
        $this->registerAi();
        $this->app->singleton(ErrorReporter::class);

        // Tüm /api/ingest/* trafiğinin tek çıkışı. Singleton çünkü durumu yok
        // ve hem kuyruk işi hem HTTP isteği içinden çözülüyor.
        $this->app->singleton(IngestClient::class);
        $this->app->singleton(TalivioOps::class);

        // Davranışsal insan doğrulaması (human-check bileşeni + Human kuralı).
        $this->app->singleton(HumanCheck::class);

        $this->registerInfra();
        $this->registerSecurity();
    }

    /**
     * İstek başına CSP nonce'u (Talivio\Sdk\Http\Security\Csp).
     *
     * `scoped`, `singleton` DEĞİL: nonce isteğe özgüdür ve iki isteğin aynı
     * nonce'u paylaşması nonce'un tek işini (bu sayfaya ait olduğunu
     * kanıtlamak) ortadan kaldırır. Octane altında `scoped` her istekte
     * sıfırlanır, `singleton` süreç boyu yaşardı.
     */
    private function registerSecurity(): void
    {
        $this->app->scoped(Csp::class);
    }

    /**
     * Alan adı / DNS / barındırma / posta istemcileri (Talivio\Sdk\Infra).
     *
     * NEDEN SDK'DA: aynı Namecheap/Cloudflare/Ploi istemcisi Shops ve
     * Contentio'ya ayrı ayrı yazılmıştı (2026-09-05 incelemesi: ~5.300
     * satır, iki farklı API yüzeyi, aynı allowlist tuzakları iki kez
     * keşfedilmiş). Bütün ürünler tek sunucuda ve tek hesap setiyle
     * çalıştığı için istemci de tek yerde durmalı; ürünlerde yalnız tenant
     * modeli, faturalama ve UI kalır.
     *
     * Sözleşmeler `talivio.infra.{registrar,dns,host,mail}` sürücü adına
     * göre bağlanır. Kimlik bilgisi eksikse SÖZLEŞME yine çözümlenir ama
     * her çağrıda eksik env değişkenini adıyla söyleyen
     * NotConfiguredException fırlatan bir vekile (Unconfigured*) bağlanır:
     * constructor'dan enjekte eden bir controller yapılandırılmamış
     * ortamda da kurulabilmeli (2026-09-05: Shops canlıda anahtarlar
     * yokken çözümleme anında patlayınca alan adı sayfaları 500 verdi).
     * SOMUT sınıf (`app(Namecheap::class)`) ise çözümleme anında fırlatır
     * — onu isteyen özellikle onu istiyordur. "Yapılandırılmamış"
     * durumunu göstermek isteyen ürün `Namecheap::fromConfig()` (null)
     * kullanır. Ürün kendi bağlamasını yaparsa (ör. testte Fake*) o
     * kazanır: `bind` sırası ürünün service provider'ında sonradır.
     */
    private function registerInfra(): void
    {
        /*
         * Genel DNS okuyucu. Sürücü seçimi YOK ve kimlik bilgisi
         * istemez — okuduğu şey herkese açık DNS, bir satıcının kontrol
         * düzlemi değil. Bu yüzden aşağıdaki tabloya girmiyor:
         * yapılandırılmamış bir hâli olamaz.
         */
        $this->app->singleton(DnsProbe::class, SystemDnsProbe::class);

        $drivers = [
            Registrar::class => ['talivio.infra.registrar', UnconfiguredRegistrar::class, [
                Namecheap::NAME => Namecheap::class,
                Openprovider::NAME => Openprovider::class,
            ]],
            Dns::class => ['talivio.infra.dns', UnconfiguredDns::class, [Cloudflare::NAME => Cloudflare::class]],
            Host::class => ['talivio.infra.host', UnconfiguredHost::class, [Ploi::NAME => Ploi::class]],
            Mail::class => ['talivio.infra.mail', UnconfiguredMail::class, [Mailcow::NAME => Mailcow::class]],
            // What a PRODUCT should inject. Mail::class above is the raw
            // shared instance and reaches domains no product owns.
            ProductMail::class => ['talivio.infra.product_mail', UnconfiguredProductMail::class, [MailioGateway::NAME => MailioGateway::class]],
        ];

        foreach ($drivers as $contract => [$configKey, $unconfigured, $implementations]) {
            foreach ($implementations as $class) {
                $this->app->bind($class, fn () => $class::fromConfig()
                    ?? throw NotConfiguredException::forService($class::NAME, $class::requiredEnv()));
            }

            $this->app->bind($contract, function () use ($configKey, $unconfigured, $implementations) {
                $driver = (string) config($configKey, array_key_first($implementations));

                if (! isset($implementations[$driver])) {
                    throw new InvalidArgumentException("Unknown {$configKey} driver \"{$driver}\" — expected one of: ".implode(', ', array_keys($implementations)).'.');
                }

                $class = $implementations[$driver];

                return $class::fromConfig() ?? new $unconfigured($class::NAME, $class::requiredEnv());
            });
        }
    }

    public function boot(): void
    {
        $this->registerSocialiteDriver();
        $this->registerRoutes();
        $this->registerErrorReporting();
        $this->registerViews();
        $this->registerTranslations();
        $this->registerMailTheme();
        $this->registerPublishing();
        $this->registerBladeDirectives();

        if ($this->app->runningInConsole()) {
            $this->commands([HeartbeatCommand::class, MigrationReportCommand::class, MigrationProbeCommand::class]);
            $this->scheduleHeartbeat();
            $this->scheduleMigrationProbe();
        }
    }

    /**
     * talivio-ai 2.MIG.24/27 — göç ölçümünün DÜZENLİ koşması.
     *
     * ⚠️ NEDEN MERKEZDE: heartbeat'te öğrenildiği gibi, her ürüne elle
     * kopyalanan bir `Schedule` satırı bir üründe unutulur ve o ürün sessizce
     * ölçüm dışında kalır — göç ölçümünde bu tam olarak yaşandı (samplio,
     * VoxSim ve rivo'da haftalarca tek satır bile birikmemişti).
     *
     * ⚠️ VARSAYILAN KAPALI ve bu bilinçli: probe İKİNCİ BİR FATURA üretir
     * (her istem iki yolda birden koşar). Açmak, göç ölçümü için para
     * harcamayı kabul etmektir; göç bitince kapatılmalıdır.
     *
     * ⚠️ Ayrıca `migration.shadow` da açık olmalı — komut zaten onu şart
     * koşuyor. İki bayrağı birleştirmedim: biri "ölçüm yapılsın mı", diğeri
     * "kendiliğinden mi koşsun" sorusunu cevaplıyor ve bir ürün ölçümü elle
     * tetiklemeyi tercih edebilir.
     */
    private function scheduleMigrationProbe(): void
    {
        if (! config('talivio-ai.migration.probe_schedule', false)) {
            return;
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            /*
             * Günde iki kez: 20 örneklik karar eşiğine ~10 günde ulaşır.
             * Daha sık koşmak ölçümü hızlandırmaz — asıl gecikme sağlayıcı
             * değişkenliğinin farklı saatlerde görülmesi, çağrı sayısı değil.
             */
            $schedule->command('talivio:ai-migration-probe')
                ->twiceDaily(4, 16)
                ->withoutOverlapping()
                ->runInBackground();
        });
    }

    /**
     * Heartbeat'i SDK'nın kendisi zamanlar. Önceden komut yalnızca kaydediliyor,
     * zamanlaması her ürünün kendi `routes/console.php`'sine elle kopyalanıyordu
     * — kopyalanmayan ürünler hiç sinyal göndermiyor, hub tarafındaki bekçi de
     * onları sürekli "sustu" sanıyordu. Artık paketi kuran her ürün otomatik
     * olarak sinyal verir.
     *
     * Token yoksa komut zaten no-op olduğu için geliştirme ortamında zararsızdır.
     * Ürün zamanlamayı kendi devralmak isterse config'te `heartbeat_schedule`'ı
     * false yapıp kendi Schedule kaydını yazar.
     */
    private function scheduleHeartbeat(): void
    {
        if (! config('talivio.heartbeat_schedule', true)) {
            return;
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('talivio:heartbeat')
                ->everyFiveMinutes()
                ->withoutOverlapping()
                ->runInBackground();
        });
    }

    /**
     * AI istemcisi — ağ geçidine bağlanan tek katman.
     *
     * ⚠️ Sağlayıcıya DOĞRUDAN yol yok (ADR-17): burada yalnızca ağ geçidinin
     * adresi ve anahtarı var. Ağ geçidi erişilemezse ürün AI'sız çalışmaya
     * devam eder (bkz. `talivio-ai.degradation`), kendi başına sağlayıcıya gitmez.
     */
    private function registerAi(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/talivio-ai.php', 'talivio-ai');

        /*
         * Taşıma sürücüsü yapılandırmadan seçilir. `fake` varsayılanı TEST
         * ortamı içindir: ürünlerin test paketi ağ geçidine çıkarsa testler
         * ağa, gecikmeye ve bütçeye bağımlı hâle gelir.
         */
        $this->app->singleton(Transport::class, function ($app): Transport {
            if (config('talivio-ai.driver') === 'fake') {
                return new FakeTransport;
            }

            return new HttpTransport(
                $app->make(HttpFactory::class),
                (string) config('talivio-ai.base_url'),
                config('talivio-ai.key'),
                (int) config('talivio-ai.timeout'),
                (int) config('talivio-ai.connect_timeout'),
            );
        });

        // Varsayılan bozulma davranışı: özellik sessizce kapanır. Şablon yedeği
        // ürüne göre yanlış olabilir; ürün kendi stratejisini bağlar.
        $this->app->bind(DegradationStrategy::class, DisableFeature::class);

        $this->app->singleton(CircuitBreaker::class, fn ($app): CircuitBreaker => new CircuitBreaker(
            $app->make(CacheRepository::class),
            (int) config('talivio-ai.degradation.breaker_failures'),
            (int) config('talivio-ai.degradation.breaker_cooldown_seconds'),
        ));

        $this->app->singleton(GatewayTextClient::class, fn ($app): GatewayTextClient => new GatewayTextClient(
            $app->make(TalivioAi::class),
        ));

        /*
         * `TextClient` sözleşmesi göç durumuna göre bağlanır (talivio-ai
         * Faz 2). Ürün, eski istemcisini `talivio-ai.migration.legacy` ile
         * bildiriyorsa GÖLGE KOŞU devreye giriyor; bildirmiyorsa doğrudan ağ
         * geçidi bağlanıyor.
         *
         * ⚠️ Bu bağlama ürünün kendi sınıfını EZMEZ: ürün `TextClient`'ı
         * kendisi bağlarsa (ör. özel bir sarmalayıcı) o kazanır — `bind`
         * sırası ürünün service provider'ında sonradır.
         */
        $this->app->bind(TextClient::class, function ($app): TextClient {
            $legacy = config('talivio-ai.migration.legacy');

            if (! is_string($legacy) || ! class_exists($legacy)) {
                return $app->make(GatewayTextClient::class);
            }

            return new ShadowTextClient($app->make($legacy), $app->make(GatewayTextClient::class));
        });

        $this->app->singleton(TalivioAi::class, fn ($app): TalivioAi => new TalivioAi(
            $app->make(Transport::class),
            $app->make(CircuitBreaker::class),
            $app->make(DegradationStrategy::class),
            (int) config('talivio-ai.degradation.retries'),
            (bool) config('talivio-ai.log_usage'),
        ));
    }

    private function registerSocialiteDriver(): void
    {
        Socialite::extend('talivio', function ($app) {
            $config = $app['config']['talivio'];

            return Socialite::buildProvider(TalivioProvider::class, [
                'client_id' => $config['client_id'],
                'client_secret' => $config['client_secret'],
                'redirect' => $config['redirect'] ?: url('/talivio/callback'),
            ]);
        });
    }

    private function registerRoutes(): void
    {
        Route::middleware('web')->group(function () {
            Route::get('/talivio/login', [TalivioAuthController::class, 'redirect'])->name('talivio.login');
            Route::get('/talivio/callback', [TalivioAuthController::class, 'callback'])->name('talivio.callback');
            Route::post('/talivio/support', [SupportFormController::class, 'store'])
                ->middleware('throttle:5,1')
                ->name('talivio.support.store');

            // SPA'lar için insan-doğrulama jetonu (Blade formları jetonu zaten
            // sayfaya basılmış hâlde alır, bu uca hiç gitmez).
            Route::get('/talivio/human/token', HumanTokenController::class)
                ->middleware('throttle:30,1')
                ->name('talivio.human.token');

            Route::middleware('auth:'.config('talivio.guard'))->group(function () {
                Route::get('/talivio/link', [TalivioAuthController::class, 'link'])->name('talivio.link');
                Route::post('/talivio/unlink', [TalivioAuthController::class, 'unlink'])->name('talivio.unlink');
            });
        });

        // Server-to-server GDPR deletion callback from the hub — no session,
        // no CSRF; authenticated by HMAC signature inside the controller.
        Route::post('/talivio/account-deleted', AccountDeletionController::class)
            ->middleware('throttle:30,1')
            ->name('talivio.account-deleted');
    }

    /**
     * Hooks into the host app's own exception handler — no changes needed in
     * the product's code for error telemetry to start flowing.
     */
    private function registerErrorReporting(): void
    {
        if (! config('talivio.telemetry_enabled')) {
            return;
        }

        try {
            $handler = $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class);

            if ($handler instanceof ExceptionHandler) {
                $handler->reportable(function (Throwable $e) {
                    $this->app->make(ErrorReporter::class)->report($e);
                });
            }
        } catch (Throwable) {
            // Never let telemetry wiring break the host app's boot.
        }
    }

    /**
     * `@talivioNonce` — satır içi <script>'lerin CSP nonce'u.
     *
     *     <script @talivioNonce>...</script>
     *
     * Direktif olması, ürünlerin `{{ app(Csp::class)->nonce() }}` gibi bir
     * ifadeyi 12 şablona kopyalamasından iyidir: nonce'un nasıl üretildiği
     * değişirse şablonlara dokunmak gerekmez.
     */
    private function registerBladeDirectives(): void
    {
        $this->callAfterResolving('blade.compiler', function ($blade) {
            $blade->directive('talivioNonce', fn () => "<?php echo app('".Csp::class."')->attribute(); ?>");
        });
    }

    private function registerViews(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'talivio');
    }

    /**
     * `<x-talivio::footer />` metinleri (`talivio::footer.*`).
     *
     * Bileşenin içine sabit metin yazılmadı: alt bilgi 20 küsur üründe basılıyor
     * ve hepsi Türkçe değil — İngilizce arayüzlü bir ürünün alt bilgisi Türkçe
     * çıkardı. Ürün metni değiştirmek isterse `talivio-lang` etiketiyle yayınlar.
     */
    private function registerTranslations(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'talivio');
    }

    /**
     * Brands every Markdown mailable/notification (password resets, email
     * verification, receipts, …) across all Talivio products with a shared
     * look: the SDK's mail-theme dir is appended to Laravel's markdown mail
     * paths (so a product's own resources/views/vendor/mail overrides still
     * win), and the "talivio" CSS theme becomes the default unless the host
     * app already picked a custom theme of its own.
     */
    private function registerMailTheme(): void
    {
        $paths = (array) config('mail.markdown.paths', []);

        config(['mail.markdown.paths' => array_values(array_unique(array_merge(
            $paths,
            [__DIR__.'/../resources/views/mail-theme'],
        )))]);

        if (config('mail.markdown.theme', 'default') === 'default') {
            config(['mail.markdown.theme' => 'talivio']);
        }
    }

    private function registerPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/talivio.php' => config_path('talivio.php'),
        ], 'talivio-config');

        $this->publishes([
            __DIR__.'/../database/migrations/add_talivio_id_to_users_table.php.stub' => database_path('migrations/'.date('Y_m_d_His', time()).'_add_talivio_id_to_users_table.php'),
        ], 'talivio-migrations');

        // AI istemcisinin yapılandırması ayrı yayınlanır: SSO kullanan ama AI
        // kullanmayan bir ürünün config dizinine gereksiz dosya düşmesin.
        $this->publishes([
            __DIR__.'/../config/talivio-ai.php' => config_path('talivio-ai.php'),
        ], 'talivio-ai-config');

        /*
         * Alt bilgi metinleri — YALNIZCA metni değiştirmek isteyen ürün yayınlar.
         *
         * ⚠️ Görsel dilin CSS'i (resources/css/talivio-design.css) BİLEREK
         * yayınlanmıyor: kopyalanan dosya güncellenmez ve sapar — bu paketin
         * var oluş sebebinin tam tersi. Ürün onu vendor yolundan `@import`
         * eder (README "Tasarım dili").
         */
        $this->publishes([
            __DIR__.'/../lang' => lang_path('vendor/talivio'),
        ], 'talivio-lang');
    }
}
