<?php

declare(strict_types=1);

namespace App\Application;

use App\Storage\IdempotencyStore;
use App\Stripe\StripeSaleDataMapper;
use App\Stripe\StripeWebhookVerifier;
use App\Support\FileLogger;
use App\WFirma\WFirmaClient;

final class StripeWebhookHandler
{
    public function __construct(
        private readonly StripeWebhookVerifier $verifier,
        private readonly StripeSaleDataMapper $mapper,
        private readonly WFirmaClient $wfirmaClient,
        private readonly IdempotencyStore $idempotencyStore,
        private readonly FileLogger $logger,
    ) {
    }

    public function handle(string $payload, ?string $signatureHeader): array
    {
        $event = $this->verifier->verify($payload, $signatureHeader);

        if ($this->idempotencyStore->hasEvent($event->id)) {
            $this->logger->info('Stripe event already processed', ['event_id' => $event->id, 'event_type' => $event->type]);

            return [
                'status' => 'already_processed',
                'event_id' => $event->id,
                'event_type' => $event->type,
            ];
        }

        $sale = $this->mapper->map($event);

        if ($this->idempotencyStore->hasPaymentReference($sale->paymentReference)) {
            $this->idempotencyStore->recordEvent($sale->eventId, [
                'recorded_at' => gmdate(DATE_ATOM),
                'status' => 'duplicate_payment_reference',
                'payment_reference' => $sale->paymentReference,
            ]);

            $this->logger->warning('Stripe payment reference already imported', [
                'event_id' => $sale->eventId,
                'payment_reference' => $sale->paymentReference,
            ]);

            return [
                'status' => 'already_processed',
                'event_id' => $sale->eventId,
                'event_type' => $sale->eventType,
                'payment_reference' => $sale->paymentReference,
            ];
        }

        $response = $this->wfirmaClient->createPaidInvoice($sale->invoicePayload);
        $invoiceId = $this->wfirmaClient->extractInvoiceId($response);

        $this->idempotencyStore->recordProcessed($sale->eventId, $sale->paymentReference, [
            'event_type' => $sale->eventType,
            'invoice_id' => $invoiceId,
            'response' => $response,
        ]);

        $this->logger->info('Stripe payment imported into wFirma', [
            'event_id' => $sale->eventId,
            'event_type' => $sale->eventType,
            'payment_reference' => $sale->paymentReference,
            'invoice_id' => $invoiceId,
        ] + $sale->context);

        return [
            'status' => 'processed',
            'event_id' => $sale->eventId,
            'event_type' => $sale->eventType,
            'payment_reference' => $sale->paymentReference,
            'invoice_id' => $invoiceId,
        ];
    }
}
