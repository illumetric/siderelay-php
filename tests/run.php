<?php

require __DIR__ . '/../src/Client.php';
use SideRelay\Client;

function check(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}
$token = 'sr_token_test_' . str_repeat('a', 24) . '.' . str_repeat('b', 43);
$event = ['event_id' => 'purchase:123', 'occurred_at' => '2026-10-06T12:00:00Z', 'properties' => ['transaction_id' => '123', 'value' => 10, 'currency' => 'EUR']];
$bodies = [];
$client = new Client($token, transport: function ($url, $body, $headers) use (&$bodies, $token, $event) {
    check(in_array('Authorization: Bearer ' . $token, $headers), 'bearer header');
    $bodies[] = $body;
    if (count($bodies) === 1) {
        return ['status' => 503, 'body' => ''];
    }
    $payload = json_decode($body, true);
    check($payload['consent']['advertising'] === 'unknown', 'unknown permission');
    return ['status' => 202, 'body' => json_encode(['durable' => true, 'status' => 'accepted', 'event_id' => $event['event_id']])];
}, sleep: fn ($ms) => null);
$result = $client->purchase($event + ['identity' => ['email' => ' TEST@example.com ']]);
check($result['status'] === 'received' && $result['attempts'] === 2, 'retry acceptance');
check($bodies[0] === $bodies[1], 'stable body');
check(json_decode($bodies[0], true)['identity']['email_sha256'] === hash('sha256', 'test@example.com'), 'normalization');
check($client->purchase(array_replace($event, ['event_id' => '']))['status'] === 'configuration', 'validation');
$denied = new Client($token, transport: fn () => ['status' => 401, 'body' => '']);
check($denied->purchase($event)['attempts'] === 1, 'no auth retries');
$limited = new Client($token, transport: fn () => ['status' => 429, 'body' => '', 'retry_after' => '60']);
check($limited->purchase($event)['attempts'] === 1, 'retry after');
check($client->batch(array_fill(0, 101, $event))['status'] === 'configuration', 'batch limit');
$gpc = Client::normalizeEvent($event + ['event_name' => 'purchase', 'consent' => ['advertising' => 'granted', 'global_privacy_control' => true]]);
check($gpc['consent']['advertising'] === 'denied', 'GPC');

$fixtures = json_decode(file_get_contents(__DIR__ . '/../fixtures/protocol-v1.json'), true);
check(Client::normalizeEvent($fixtures['input'])['occurred_at'] === '2026-10-06T12:00:00.000Z', 'fixture timestamp');
foreach ($fixtures['hashes'] as $fixture) {
    check(Client::hashIdentity($fixture['input'], $fixture['type']) === $fixture['sha256'], 'fixture hash');
}

$permissionEvent = Client::normalizeEvent($event + ['event_name' => 'purchase', 'permissions' => ['retention' => 'denied']]);
check($permissionEvent['permissions']['retention'] === 'denied', 'Separate retention choice');

$batchClient = new Client($token, transport: function ($url, $body) {
    $payload = json_decode($body, true);
    check(str_ends_with($url, '/v1/batch'), 'batch endpoint');
    return ['status' => 202, 'body' => json_encode(['acknowledgements' => array_map(static fn ($event) => ['event_id' => $event['event_id'], 'status' => 'accepted', 'durable' => true], $payload['events'])])];
});
check($batchClient->batch([$event + ['event_name' => 'purchase']])['status'] === 'received', 'durable batch receipt');
$leadClient = new Client($token, transport: function ($url, $body) {
    $payload = json_decode($body, true);
    check($payload['event_name'] === 'generate_lead', 'authoritative lead name');
    return ['status' => 202, 'body' => json_encode(['event_id' => $payload['event_id'], 'status' => 'accepted', 'durable' => true])];
});
check($leadClient->lead(['event_id' => 'lead:123', 'occurred_at' => $event['occurred_at']])['status'] === 'received', 'lead receipt');
$exhausted = new Client($token, transport: static fn () => ['status' => 503, 'body' => ''], sleep: static fn ($ms) => null);
check($exhausted->purchase($event)['attempts'] === 3, 'three total attempts');
$invalidReceipt = new Client($token, transport: static fn () => ['status' => 202, 'body' => json_encode(['event_id' => 'wrong', 'status' => 'accepted', 'durable' => true])]);
check($invalidReceipt->purchase($event)['status'] === 'retry', 'mismatched receipt is not received');
echo "PHP SDK checks passed\n";
