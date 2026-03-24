<?php

declare(strict_types=1);

namespace App\Application;

final class MappedSaleData
{
    public function __construct(
        public readonly string $eventId,
        public readonly string $eventType,
        public readonly string $paymentReference,
        public readonly string $paymentDate,
        public readonly array $invoicePayload,
        public readonly array $context,
    ) {
    }
}
