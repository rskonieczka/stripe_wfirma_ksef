<?php

declare(strict_types=1);

namespace App\Storage;

final class IdempotencyStore
{
    public function __construct(
        private readonly string $directory,
    ) {
        if (! is_dir($this->directory)) {
            mkdir($this->directory, 0775, true);
        }
    }

    public function hasEvent(string $eventId): bool
    {
        return is_file($this->pathFor('event', $eventId));
    }

    public function hasPaymentReference(string $paymentReference): bool
    {
        return is_file($this->pathFor('payment', $paymentReference));
    }

    public function recordEvent(string $eventId, array $payload): void
    {
        $this->write($this->pathFor('event', $eventId), $payload);
    }

    public function recordProcessed(string $eventId, string $paymentReference, array $payload): void
    {
        $record = [
            'recorded_at' => gmdate(DATE_ATOM),
            'payload' => $payload,
        ];

        $this->write($this->pathFor('event', $eventId), $record);
        $this->write($this->pathFor('payment', $paymentReference), $record);
    }

    private function write(string $path, array $payload): void
    {
        $tmpPath = $path . '.tmp';
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        file_put_contents($tmpPath, $json, LOCK_EX);
        rename($tmpPath, $path);
    }

    private function pathFor(string $prefix, string $key): string
    {
        $safeKey = preg_replace('/[^A-Za-z0-9._-]/', '_', $key) ?: 'unknown';

        return sprintf('%s/%s-%s.json', rtrim($this->directory, '/'), $prefix, $safeKey);
    }
}
