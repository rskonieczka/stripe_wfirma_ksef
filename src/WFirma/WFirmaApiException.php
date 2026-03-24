<?php

declare(strict_types=1);

namespace App\WFirma;

use RuntimeException;

final class WFirmaApiException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly array $responseBody = [],
        private readonly int $httpStatusCode = 0,
    ) {
        parent::__construct($message, $httpStatusCode);
    }

    public function responseBody(): array
    {
        return $this->responseBody;
    }

    public function httpStatusCode(): int
    {
        return $this->httpStatusCode;
    }
}
