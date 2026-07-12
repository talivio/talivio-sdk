# talivio/sdk

Talivio Accounts SSO + central error/support telemetry for every Talivio product. Ports the same revenue-engine pattern used across Talivio's SaaS apps: one package, drop it in, done.

## Install (per product)

1. **Register the product in talivio.com** — `/admin/applications` → create → fill name + URL. This provisions an OAuth client and an ingest token, shown once.

2. **Add the package** (path repository until this is on a private Packagist):

```json
"repositories": [
    { "type": "path", "url": "../talivio-sdk" }
],
"require": {
    "talivio/sdk": "*"
}
```

```bash
composer require talivio/sdk
php artisan vendor:publish --tag=talivio-migrations
php artisan vendor:publish --tag=talivio-config
php artisan migrate
```

3. **.env**:

```
TALIVIO_HUB_URL=https://talivio.com
TALIVIO_CLIENT_ID=...
TALIVIO_CLIENT_SECRET=...
TALIVIO_INGEST_TOKEN=...
```

4. **Add the button** to your login/register Blade views:

```blade
<x-talivio::accounts-button />
```

5. **Error telemetry and heartbeat** work with zero extra code — the package hooks into your app's own exception handler automatically. Schedule the heartbeat in `routes/console.php`:

```php
Schedule::command('talivio:heartbeat')->everyFiveMinutes();
```

6. **Support form** (optional): drop `<x-talivio::support-form />` anywhere.

That's it — login button, error logs, and support tickets all show up in talivio.com's "Talivio Accounts" / "Talivio Ops" admin panels.
