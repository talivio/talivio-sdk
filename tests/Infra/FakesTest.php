<?php

namespace Talivio\Sdk\Tests\Infra;

use Talivio\Sdk\Infra\Contracts\Dns;
use Talivio\Sdk\Infra\Contracts\Host;
use Talivio\Sdk\Infra\Contracts\Mail;
use Talivio\Sdk\Infra\Contracts\Registrar;
use Talivio\Sdk\Infra\Testing\FakeDns;
use Talivio\Sdk\Infra\Testing\FakeHost;
use Talivio\Sdk\Infra\Testing\FakeMail;
use Talivio\Sdk\Infra\Testing\FakeRegistrar;
use Talivio\Sdk\Tests\TestCase;

/**
 * The fakes are what product test suites bind instead of the real
 * clients — a product's own binding must beat the SDK's, and a whole
 * "register → zone → records → attach → certificate → mail" pass must
 * hold together in memory.
 */
class FakesTest extends TestCase
{
    public function test_a_products_own_binding_wins_over_the_sdk_default(): void
    {
        $this->app->instance(Registrar::class, $registrar = new FakeRegistrar);
        $this->app->instance(Dns::class, $dns = new FakeDns);
        $this->app->instance(Host::class, $host = new FakeHost);
        $this->app->instance(Mail::class, $mail = new FakeMail);

        $this->assertSame($registrar, $this->app->make(Registrar::class));
        $this->assertSame($dns, $this->app->make(Dns::class));
        $this->assertSame($host, $this->app->make(Host::class));
        $this->assertSame($mail, $this->app->make(Mail::class));
    }

    public function test_a_full_provisioning_pass_holds_together(): void
    {
        $registrar = new FakeRegistrar;
        $dns = new FakeDns;
        $host = new FakeHost;
        $mail = new FakeMail;

        $registrar->taken = ['taken.com'];
        $this->assertFalse($registrar->checkAvailability('taken.com')['available']);
        $this->assertTrue($registrar->checkAvailability('myshop.com')['available']);

        $zone = $dns->ensureZone('myshop.com');
        $id = $registrar->register('myshop.com', ['name' => 'Jane'], $zone['nameservers']);

        $this->assertSame($zone['nameservers'], $registrar->nameservers['myshop.com']);
        $this->assertTrue($dns->zoneIsActive($zone['id']));

        $dns->ensureRecords($zone['id'], 'myshop.com', $host->serverIp());
        $this->assertSame('203.0.113.10', $dns->record($zone['id'], 'A', 'myshop.com')['content']);
        $this->assertSame($zone['id'], $dns->findZoneId('www.myshop.com'));

        $host->attachDomain('myshop.com');
        $host->requestCertificate('myshop.com', ['www.myshop.com']);
        $this->assertTrue($host->certificateIssued('myshop.com'));

        $mail->addDomain('myshop.com');
        $mail->addMailbox('myshop.com', 'info', 'secret');
        foreach ($mail->dnsRecords('myshop.com') as $record) {
            $dns->upsertRecord($zone['id'], $record['type'], $record['name'], $record['content'], priority: $record['priority'] ?? null);
        }

        $this->assertSame(10, $dns->record($zone['id'], 'MX', 'myshop.com')['priority']);
        $this->assertCount(1, $mail->listMailboxes('myshop.com'));

        $site = $host->createSite('client.example');
        $host->requestSiteCertificate($site['id'], ['client.example']);
        $this->assertTrue($host->siteCertificateIssued($site['id'], 'client.example'));
        $this->assertSame('1000', $id);
    }

    public function test_fake_host_created_sites_carry_the_ops_inventory_defaults(): void
    {
        $host = new FakeHost;

        $site = $host->createSite('client.example', ['project_type' => 'wordpress', 'php_version' => '8.2', 'system_user' => 'client']);

        $listed = $host->listSites()[0];

        $this->assertSame($site['id'], $listed['id']);
        $this->assertSame('client.example', $listed['domain']);
        $this->assertSame('active', $listed['status']);
        $this->assertSame('wordpress', $listed['project_type']);
        $this->assertSame('8.2', $listed['php_version']);
        $this->assertSame('client', $listed['system_user']);
        $this->assertNull($listed['last_deploy_at']);
        $this->assertSame(0, $listed['disk_usage_mb']);
        $this->assertFalse($listed['has_repository']);
        $this->assertSame('2026-01-01 00:00:00', $listed['created_at']);
    }

    public function test_fake_host_site_certificates_reflect_the_issued_state(): void
    {
        $host = new FakeHost;
        $site = $host->createSite('client.example');

        $this->assertSame([], $host->siteCertificates($site['id']));

        $host->requestSiteCertificate($site['id'], ['client.example']);

        $this->assertSame([[
            'id' => 9000 + $site['id'],
            'domains' => ['client.example'],
            'status' => 'active',
            'type' => 'letsencrypt',
            'expires_at' => $host->certificateExpiresAt,
        ]], $host->siteCertificates($site['id']));
    }

    public function test_fake_host_site_certificates_stay_pending_without_issue_on_request(): void
    {
        $host = new FakeHost;
        $host->issueOnRequest = false;
        $site = $host->createSite('client.example');

        $host->requestSiteCertificate($site['id'], ['client.example']);

        $this->assertSame([[
            'id' => 9000 + $site['id'],
            'domains' => ['client.example'],
            'status' => 'pending',
            'type' => 'letsencrypt',
            'expires_at' => null,
        ]], $host->siteCertificates($site['id']));
    }

    public function test_the_fail_knob_makes_every_call_throw(): void
    {
        $dns = new FakeDns;
        $dns->failWith = 'Cloudflare is down';

        $this->expectExceptionMessage('Cloudflare is down');

        $dns->ensureZone('myshop.com');
    }
}
