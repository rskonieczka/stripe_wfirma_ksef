<?php

declare(strict_types=1);

namespace App\Stripe;

use Stripe\Event;
use Stripe\Webhook;

final class StripeWebhookVerifier
{
    public function __construct(
        private readonly string $webhookSecret,
    ) {
    }

    public function verify(string $payload, ?string $signatureHeader): Event
    {
        if ($signatureHeader === null || $signatureHeader === '') {
            throw new \UnexpectedValueException('Brakuje nagłówka Stripe-Signature.');
        }

        return Webhook::constructEvent($payload, $signatureHeader, $this->webhookSecret);
    }
}
