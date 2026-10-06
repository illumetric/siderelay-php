# SideRelay for PHP

Send confirmed purchases and leads from your PHP backend to SideRelay.
Requires PHP 8.2+, cURL, and mbstring. No framework dependency.

```sh
composer config repositories.siderelay vcs https://github.com/illumetric/siderelay-php
composer require siderelay/php:^0.1.0
```

```php
use SideRelay\Client;
$client = new Client(getenv('SIDERELAY_TOKEN'));
$result = $client->purchase([
    'event_id' => 'purchase:' . $order->id,
    'occurred_at' => $order->paidAt->format(DATE_ATOM),
    'consent' => $order->savedConsent,
    'properties' => ['transaction_id' => (string) $order->id, 'value' => $order->total, 'currency' => $order->currency]
]);
```

Create a backend connection in SideRelay and copy its token. Keep it on your
server. Never put it in browser code.

## Results and reliable delivery

- **Received** (`received`): safely collected. Check SideRelay Activity for delivery.
- **Try again** (`retry`): retry through your application's background queue.
- **Check configuration** (`configuration`): fix the token or event details first.

Send after the business transaction commits. Tracking must never fail checkout.
The SDK makes three attempts with five-second request timeouts and a twenty-second
call budget. Retry-After is honored. Your existing queue owns durable retries.
Always reuse the same event ID and occurrence time, including browser copies.

`track($event)`, `purchase($event)`, `lead($event)`, and `batch($events)` return arrays.
Lead maps to `generate_lead`. Every event needs an ID and occurrence time. Limits:
96 KB per event; 200 KB and 100 events per batch.

Pass the actual saved permission choice as `consent`. Missing choices remain
unknown. Pass `global_privacy_control`, `sale_sharing_opt_out`, and
`targeted_advertising_opt_out` when applicable. Advertising opt-outs override grants.
Use the website's `SideRelay.getBackendContext(eventId)` helper for handoff.
Raw email, phone, and customer ID fields are hashed before transmission;
`Client::hashIdentity($value, $type)` supplies standalone normalization helpers.
Phone numbers must already include their country code.

An optional second constructor argument overrides the endpoint for local development
(`http://localhost:5178`). Production uses HTTPS. Redirects are never followed.
Transport and sleep callbacks are injectable; tokens and payloads are never logged.

## Development and releases

Run `composer validate` and `php tests/run.php`. Fixtures are pinned to the
JavaScript repository's protocol v1 release. Publish an explicit version tag and
register the public GitHub repository on Packagist. Ordinary commits do not release.
Composer installation becomes available after Packagist registration; until then
use a Composer VCS repository pointing to this GitHub repository.
