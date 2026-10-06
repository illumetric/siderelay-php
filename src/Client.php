<?php
declare(strict_types=1);
namespace SideRelay;

final class Client
{
    private \Closure $transport;
    private \Closure $sleep;
    private string $endpoint;

    public function __construct(
        private readonly string $token,
        string $endpoint = 'https://collector.siderelay.com',
        ?callable $transport = null,
        ?callable $sleep = null,
        private readonly int $timeoutMs = 5000,
        private readonly int $maxAttempts = 3,
        private readonly int $timeBudgetMs = 20000
    ) {
        if (!preg_match('/^sr_token_(test|live)_[a-f0-9]{24}\.[A-Za-z0-9_-]{43}$/D', $token)) throw new \InvalidArgumentException('Connection token is invalid');
        $url = parse_url($endpoint);
        if (!$url || (isset($url['user']) || isset($url['pass'])) || isset($url['query']) || isset($url['fragment']) || !in_array($url['path'] ?? '/', ['', '/'], true) || (($url['scheme'] ?? '') !== 'https' && !(($url['scheme'] ?? '') === 'http' && in_array($url['host'] ?? '', ['localhost', '127.0.0.1', '[::1]'], true)))) throw new \InvalidArgumentException('Use HTTPS, or localhost for development');
        if ($maxAttempts < 1 || $maxAttempts > 10 || $timeoutMs < 1 || $timeBudgetMs < 1) throw new \InvalidArgumentException('Invalid retry settings');
        $this->endpoint = rtrim($endpoint, '/');
        $this->transport = $transport ? \Closure::fromCallable($transport) : $this->request(...);
        $this->sleep = $sleep ? \Closure::fromCallable($sleep) : static fn(int $ms) => usleep($ms * 1000);
    }

    public static function hashIdentity(string $value, string $type = 'email'): string
    {
        $normalized = mb_strtolower(trim($value), 'UTF-8');
        if ($type === 'phone') $normalized = preg_replace('/\D/', '', $normalized);
        if (in_array($type, ['first_name', 'last_name'], true)) $normalized = preg_replace('/[\p{P}\s]/u', '', $normalized);
        return hash('sha256', $normalized);
    }

    public function track(array $event): array { return $this->send([$event], false); }
    public function purchase(array $event): array { return $this->track(array_replace($event, ['event_name' => 'purchase'])); }
    public function lead(array $event): array { return $this->track(array_replace($event, ['event_name' => 'generate_lead'])); }
    public function batch(array $events): array { return $this->send($events, true); }

    public static function normalizeEvent(array $input): array
    {
        foreach (['event_id', 'event_name'] as $field) if (!isset($input[$field]) || !is_string($input[$field]) || trim($input[$field]) === '' || strlen($input[$field]) > 128) throw new \InvalidArgumentException($field . '_required');
        if (!isset($input['occurred_at']) || !is_string($input['occurred_at']) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $input['occurred_at'])) throw new \InvalidArgumentException('event_time_required');
        try { $time = new \DateTimeImmutable($input['occurred_at']); } catch (\Exception) { throw new \InvalidArgumentException('event_time_required'); }
        $consent = array_replace(['analytics' => 'unknown', 'advertising' => 'unknown', 'personalization' => 'unknown', 'source' => 'custom'], $input['consent'] ?? []);
        foreach (['analytics', 'advertising', 'personalization'] as $purpose) { if ($consent[$purpose] === 'not_required') $consent[$purpose] = 'unknown'; if (!in_array($consent[$purpose], ['unknown', 'granted', 'denied'], true)) throw new \InvalidArgumentException('invalid_consent'); }
        if (!empty($consent['global_privacy_control']) || !empty($consent['sale_sharing_opt_out']) || !empty($consent['targeted_advertising_opt_out'])) { $consent['advertising'] = 'denied'; $consent['personalization'] = 'denied'; }
        $identity = $input['identity'] ?? [];
        foreach (['email', 'phone', 'user_id'] as $type) if (!empty($identity[$type])) { $identity[$type . '_sha256'] = self::hashIdentity($identity[$type], $type); unset($identity[$type]); }
        $properties = $input['properties'] ?? [];
        if ($input['event_name'] === 'purchase' && (!isset($properties['value']) || (!is_int($properties['value']) && !is_float($properties['value'])) || !is_finite((float) $properties['value']) || !preg_match('/^[A-Z]{3}$/D', $properties['currency'] ?? '') || empty($properties['transaction_id']))) throw new \InvalidArgumentException('purchase_details_required');
        $event = ['event_id' => $input['event_id'], 'event_name' => $input['event_name'], 'occurred_at' => $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z'), 'environment' => $input['environment'] ?? 'production', 'source' => ['type' => 'backend', 'name' => 'SideRelay PHP SDK', 'version' => '0.1.0'], 'identity' => (object) $identity, 'consent' => $consent, 'context' => (object) ($input['context'] ?? []), 'properties' => (object) $properties];
        if (strlen(json_encode($event, JSON_THROW_ON_ERROR)) > 96 * 1024) throw new \InvalidArgumentException('event_too_large');
        return $event;
    }

    private function send(array $inputs, bool $batch): array
    {
        try {
            if (count($inputs) < 1 || count($inputs) > 100) throw new \InvalidArgumentException('invalid_batch');
            $events = array_map(self::normalizeEvent(...), array_values($inputs));
            $body = json_encode($batch ? ['events' => $events] : $events[0], JSON_THROW_ON_ERROR);
            if (strlen($body) > ($batch ? 200 : 96) * 1024) throw new \InvalidArgumentException('body_too_large');
        } catch (\Throwable $error) { return self::result('configuration', $error instanceof \InvalidArgumentException ? $error->getMessage() : 'invalid_event', 0); }
        $started = microtime(true); $httpStatus = null;
        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            $remaining = $this->timeBudgetMs - (microtime(true) - $started) * 1000;
            if ($remaining <= 0) return self::result('retry', 'time_budget_exceeded', $attempt - 1, $httpStatus);
            $delay = (int) (250 * 2 ** ($attempt - 1) * random_int(75, 125) / 100);
            try {
                $response = ($this->transport)($this->endpoint . '/v1/' . ($batch ? 'batch' : 'events'), $body, ['Content-Type: application/json', 'Authorization: Bearer ' . $this->token], (int) min($this->timeoutMs, $remaining));
                $httpStatus = $response['status'];
                if ($httpStatus === 202) {
                    $payload = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
                    $receipts = $batch ? ($payload['acknowledgements'] ?? []) : [$payload];
                    $valid = count($receipts) === count($events);
                    foreach ($receipts as $i => $receipt) $valid = $valid && ($receipt['durable'] ?? false) === true && in_array($receipt['status'] ?? '', ['accepted', 'duplicate'], true) && ($receipt['event_id'] ?? '') === ($events[$i]['event_id'] ?? null);
                    return self::result($valid ? 'received' : 'retry', $valid ? 'accepted' : 'invalid_acknowledgement', $attempt, $httpStatus, $valid ? $receipts : []);
                }
                if (!in_array($httpStatus, [408, 409, 425, 429], true) && $httpStatus < 500) return self::result('configuration', 'request_rejected', $attempt, $httpStatus);
                $retryAfter = $response['retry_after'] ?? null;
                if ($retryAfter !== null) { $wait = is_numeric($retryAfter) ? (float) $retryAfter * 1000 : ((strtotime($retryAfter) ?: time()) - time()) * 1000; $delay = (int) max($delay, $wait); }
            } catch (\Throwable) { /* Retry immutable IDs after ambiguous transport failures. */ }
            if ($attempt === $this->maxAttempts || (microtime(true) - $started) * 1000 + $delay >= $this->timeBudgetMs) return self::result('retry', 'temporarily_unavailable', $attempt, $httpStatus);
            ($this->sleep)($delay);
        }
        return self::result('retry', 'temporarily_unavailable', $this->maxAttempts, $httpStatus);
    }

    private function request(string $url, string $body, array $headers, int $timeout): array
    {
        $handle = curl_init($url); $retryAfter = null;
        curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => max(1, $timeout), CURLOPT_CONNECTTIMEOUT_MS => max(1, $timeout), CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$retryAfter): int { if (stripos($line, 'retry-after:') === 0) $retryAfter = trim(substr($line, 12)); return strlen($line); }]);
        try { $response = curl_exec($handle); if ($response === false) throw new \RuntimeException('transport_failed'); return ['status' => curl_getinfo($handle, CURLINFO_RESPONSE_CODE), 'body' => $response, 'retry_after' => $retryAfter]; } finally { curl_close($handle); }
    }

    private static function result(string $status, string $code, int $attempts, ?int $httpStatus = null, array $receipts = []): array
    {
        return ['status' => $status, 'message' => ['received' => 'Received', 'retry' => 'Try again', 'configuration' => 'Check configuration'][$status], 'code' => $code, 'attempts' => $attempts, 'httpStatus' => $httpStatus, 'acknowledgements' => $receipts];
    }
}
