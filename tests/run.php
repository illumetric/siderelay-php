<?php
require __DIR__ . '/../src/Client.php';
use SideRelay\Client;
function check(bool $condition, string $label): void { if (!$condition) throw new RuntimeException($label); }
$token = 'sr_token_test_' . str_repeat('a', 24) . '.' . str_repeat('b', 43);
$event = ['event_id' => 'purchase:123', 'occurred_at' => '2026-10-06T12:00:00Z', 'properties' => ['transaction_id' => '123', 'value' => 10, 'currency' => 'EUR']];
$bodies = [];
$client = new Client($token, transport: function ($url, $body, $headers) use (&$bodies, $token, $event) {
 check(in_array('Authorization: Bearer ' . $token, $headers), 'bearer header');
 $bodies[] = $body;
 if (count($bodies) === 1) return ['status' => 503, 'body' => ''];
 $payload = json_decode($body, true); check($payload['consent']['advertising'] === 'unknown', 'unknown permission');
 return ['status' => 202, 'body' => json_encode(['durable' => true, 'status' => 'accepted', 'event_id' => $event['event_id']])];
}, sleep: fn($ms) => null);
$result = $client->purchase($event + ['identity' => ['email' => ' TEST@example.com ']]);
check($result['status'] === 'received' && $result['attempts'] === 2, 'retry acceptance'); check($bodies[0] === $bodies[1], 'stable body');
check(json_decode($bodies[0], true)['identity']['email_sha256'] === hash('sha256', 'test@example.com'), 'normalization');
check($client->purchase(array_replace($event, ['event_id' => '']))['status'] === 'configuration', 'validation');
$denied = new Client($token, transport: fn() => ['status' => 401, 'body' => '']); check($denied->purchase($event)['attempts'] === 1, 'no auth retries');
$limited = new Client($token, transport: fn() => ['status' => 429, 'body' => '', 'retry_after' => '60']); check($limited->purchase($event)['attempts'] === 1, 'retry after');
check($client->batch(array_fill(0, 101, $event))['status'] === 'configuration', 'batch limit');
$gpc = Client::normalizeEvent($event + ['event_name' => 'purchase', 'consent' => ['advertising' => 'granted', 'global_privacy_control' => true]]); check($gpc['consent']['advertising'] === 'denied', 'GPC');
echo "PHP SDK checks passed\n";
$fixtures = json_decode(file_get_contents(__DIR__ . '/../fixtures/protocol-v1.json'), true);
check(Client::normalizeEvent($fixtures['input'])['occurred_at'] === '2026-10-06T12:00:00.000Z', 'fixture timestamp');
foreach ($fixtures['hashes'] as $fixture) check(Client::hashIdentity($fixture['input'], $fixture['type']) === $fixture['sha256'], 'fixture hash');
