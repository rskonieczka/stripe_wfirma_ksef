<?php

declare(strict_types=1);

namespace App\Support;

final class FileLogger
{
    public function __construct(
        private readonly string $filePath,
    ) {
        $directory = dirname($this->filePath);

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('error', $message, $context);
    }

    private function write(string $level, string $message, array $context): void
    {
        $payload = [
            'timestamp' => gmdate(DATE_ATOM),
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ];

        file_put_contents(
            $this->filePath,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
            FILE_APPEND | LOCK_EX,
        );
    }
}
