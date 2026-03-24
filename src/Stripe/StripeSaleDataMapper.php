<?php

declare(strict_types=1);

namespace App\Stripe;

use App\Application\MappedSaleData;
use App\Config\AppConfig;
use DomainException;
use Stripe\Event;

final class StripeSaleDataMapper
{
    public function __construct(
        private readonly AppConfig $config,
    ) {
    }

    public function map(Event $event): MappedSaleData
    {
        return match ($event->type) {
            'checkout.session.completed' => $this->mapCheckoutSession($event),
            'payment_intent.succeeded' => $this->mapPaymentIntent($event),
            default => throw new IgnoredStripeEventException(sprintf('Nieobsługiwany event Stripe: %s', $event->type)),
        };
    }

    private function mapCheckoutSession(Event $event): MappedSaleData
    {
        $session = $this->toArray($event->data->object);
        $paymentStatus = (string) ($session['payment_status'] ?? '');

        if (! in_array($paymentStatus, ['paid', 'no_payment_required'], true)) {
            throw new DomainException(sprintf('Sesja Stripe nie jest jeszcze opłacona. payment_status=%s', $paymentStatus));
        }

        $metadata = $this->normalizeMetadata($session['metadata'] ?? []);
        $customerDetails = $this->toArray($session['customer_details'] ?? []);
        $customer = $this->resolveCustomer(
            metadata: $metadata,
            fallbackName: $customerDetails['name'] ?? null,
            fallbackEmail: $customerDetails['email'] ?? ($session['customer_email'] ?? null),
            fallbackAddress: $this->toArray($customerDetails['address'] ?? []),
        );

        $paymentReference = $this->firstNonEmpty(
            $session['payment_intent'] ?? null,
            $session['id'] ?? null,
        );

        if ($paymentReference === null) {
            throw new DomainException('Nie udało się ustalić referencji płatności Stripe.');
        }

        $amountMinor = (int) ($session['amount_total'] ?? 0);
        $currency = strtoupper((string) ($session['currency'] ?? $this->config->wfirmaDefaultCurrency));
        $paymentDate = gmdate('Y-m-d', (int) ($event->created ?? time()));
        $lineName = $this->resolveLineName($metadata, $session['client_reference_id'] ?? null, $paymentReference);

        return new MappedSaleData(
            eventId: (string) $event->id,
            eventType: (string) $event->type,
            paymentReference: $paymentReference,
            paymentDate: $paymentDate,
            invoicePayload: $this->buildInvoicePayload($customer, $amountMinor, $currency, $lineName, $paymentReference, $paymentDate, $metadata),
            context: [
                'stripe_object_id' => $session['id'] ?? null,
                'currency' => $currency,
                'amount_minor' => $amountMinor,
                'customer_email' => $customer['email'] ?? null,
                'customer_name' => $customer['name'],
            ],
        );
    }

    private function mapPaymentIntent(Event $event): MappedSaleData
    {
        $intent = $this->toArray($event->data->object);
        $metadata = $this->normalizeMetadata($intent['metadata'] ?? []);
        $charges = $this->toArray($intent['charges']['data'] ?? []);
        $firstCharge = is_array($charges) && isset($charges[0]) ? $this->toArray($charges[0]) : [];
        $billingDetails = $this->toArray($firstCharge['billing_details'] ?? []);

        $customer = $this->resolveCustomer(
            metadata: $metadata,
            fallbackName: $billingDetails['name'] ?? null,
            fallbackEmail: $billingDetails['email'] ?? ($intent['receipt_email'] ?? null),
            fallbackAddress: $this->toArray($billingDetails['address'] ?? []),
        );

        $paymentReference = (string) ($intent['id'] ?? '');

        if ($paymentReference === '') {
            throw new DomainException('PaymentIntent nie zawiera identyfikatora.');
        }

        $amountMinor = (int) ($intent['amount_received'] ?? $intent['amount'] ?? 0);
        $currency = strtoupper((string) ($intent['currency'] ?? $this->config->wfirmaDefaultCurrency));
        $paymentDate = gmdate('Y-m-d', (int) ($event->created ?? time()));
        $lineName = $this->resolveLineName($metadata, $intent['description'] ?? null, $paymentReference);

        return new MappedSaleData(
            eventId: (string) $event->id,
            eventType: (string) $event->type,
            paymentReference: $paymentReference,
            paymentDate: $paymentDate,
            invoicePayload: $this->buildInvoicePayload($customer, $amountMinor, $currency, $lineName, $paymentReference, $paymentDate, $metadata),
            context: [
                'stripe_object_id' => $paymentReference,
                'currency' => $currency,
                'amount_minor' => $amountMinor,
                'customer_email' => $customer['email'] ?? null,
                'customer_name' => $customer['name'],
            ],
        );
    }

    private function buildInvoicePayload(
        array $customer,
        int $amountMinor,
        string $currency,
        string $lineName,
        string $paymentReference,
        string $paymentDate,
        array $metadata,
    ): array {
        if ($amountMinor <= 0) {
            throw new DomainException('Kwota płatności Stripe musi być większa od zera.');
        }

        $contractor = [
            'name' => $customer['name'],
            'altname' => $customer['name'],
            'tax_id_type' => isset($customer['nip']) ? 'nip' : 'none',
            'street' => $customer['street'],
            'zip' => $customer['zip'],
            'city' => $customer['city'],
            'country' => $customer['country'],
        ];

        if (isset($customer['email'])) {
            $contractor['email'] = $customer['email'];
        }

        if (isset($customer['nip'])) {
            $contractor['nip'] = $customer['nip'];
        }

        $invoice = [
            'type' => $this->config->wfirmaInvoiceType,
            'date' => $paymentDate,
            'sale_date' => $paymentDate,
            'paymentdate' => $paymentDate,
            'paymentmethod' => $this->config->wfirmaPaymentMethod,
            'currency' => $currency,
            'description' => $metadata['wfirma_description'] ?? sprintf('Import Stripe %s', $paymentReference),
            'contractor' => $contractor,
            'invoicecontents' => [
                'invoicecontent' => [
                    [
                        'name' => $lineName,
                        'unit' => $this->config->wfirmaDefaultUnit,
                        'count' => '1',
                        'price' => $this->formatAmount($amountMinor, $currency),
                        'price_modified' => '0',
                        'vat' => $metadata['wfirma_vat_rate'] ?? $this->config->wfirmaDefaultVatRate,
                    ],
                ],
            ],
        ];

        if (($metadata['wfirma_currency'] ?? null) !== null) {
            $invoice['currency'] = strtoupper($metadata['wfirma_currency']);
        }

        return [
            'api' => [
                'invoices' => [
                    'invoice' => [
                        $invoice,
                    ],
                ],
            ],
        ];
    }

    private function resolveCustomer(array $metadata, ?string $fallbackName, ?string $fallbackEmail, array $fallbackAddress): array
    {
        $name = $this->firstNonEmpty(
            $metadata['wfirma_customer_name'] ?? null,
            $metadata['customer_name'] ?? null,
            $fallbackName,
            $fallbackEmail,
        );

        $street = $this->firstNonEmpty(
            $metadata['wfirma_street'] ?? null,
            $metadata['customer_street'] ?? null,
            $this->joinStreet($fallbackAddress),
        );
        $zip = $this->firstNonEmpty(
            $metadata['wfirma_zip'] ?? null,
            $metadata['customer_zip'] ?? null,
            $fallbackAddress['postal_code'] ?? null,
        );
        $city = $this->firstNonEmpty(
            $metadata['wfirma_city'] ?? null,
            $metadata['customer_city'] ?? null,
            $fallbackAddress['city'] ?? null,
        );
        $country = strtoupper($this->firstNonEmpty(
            $metadata['wfirma_country'] ?? null,
            $metadata['customer_country'] ?? null,
            $fallbackAddress['country'] ?? 'PL',
        ) ?? 'PL');

        foreach (['name' => $name, 'street' => $street, 'zip' => $zip, 'city' => $city] as $field => $value) {
            if ($value === null || $value === '') {
                throw new DomainException(sprintf('Brakuje danych kontrahenta wymaganych przez wFirma: %s. Uzupełnij adres rozliczeniowy w Stripe albo przekaż metadata wfirma_%s.', $field, $field));
            }
        }

        $customer = [
            'name' => $name,
            'street' => $street,
            'zip' => $zip,
            'city' => $city,
            'country' => $country,
        ];

        $email = $this->firstNonEmpty(
            $metadata['wfirma_email'] ?? null,
            $metadata['customer_email'] ?? null,
            $fallbackEmail,
        );

        if ($email !== null) {
            $customer['email'] = $email;
        }

        $nip = $this->sanitizeTaxId($this->firstNonEmpty(
            $metadata['wfirma_nip'] ?? null,
            $metadata['customer_nip'] ?? null,
            $metadata['tax_id'] ?? null,
            $metadata['nip'] ?? null,
        ));

        if ($nip !== null) {
            $customer['nip'] = $nip;
        }

        return $customer;
    }

    private function resolveLineName(array $metadata, ?string $fallback, string $paymentReference): string
    {
        return $this->firstNonEmpty(
            $metadata['wfirma_item_name'] ?? null,
            $metadata['product_name'] ?? null,
            $metadata['item_name'] ?? null,
            $metadata['description'] ?? null,
            $fallback,
            sprintf('Płatność Stripe %s', $paymentReference),
        ) ?? sprintf('Płatność Stripe %s', $paymentReference);
    }

    private function normalizeMetadata(array $metadata): array
    {
        $normalized = [];

        foreach ($metadata as $key => $value) {
            $normalized[(string) $key] = is_scalar($value) ? trim((string) $value) : '';
        }

        return $normalized;
    }

    private function formatAmount(int $amountMinor, string $currency): string
    {
        $zeroDecimalCurrencies = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];
        $divisor = in_array(strtoupper($currency), $zeroDecimalCurrencies, true) ? 1 : 100;
        $precision = $divisor === 1 ? 0 : 2;

        return number_format($amountMinor / $divisor, $precision, '.', '');
    }

    private function sanitizeTaxId(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $sanitized = preg_replace('/[^0-9A-Za-z]/', '', $value);

        return $sanitized === '' ? null : $sanitized;
    }

    private function firstNonEmpty(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (! is_scalar($value) && $value !== null) {
                continue;
            }

            $candidate = trim((string) $value);

            if ($candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    private function joinStreet(array $address): ?string
    {
        $parts = array_filter([
            $address['line1'] ?? null,
            $address['line2'] ?? null,
        ], static fn (mixed $value): bool => is_string($value) && trim($value) !== '');

        if ($parts === []) {
            return null;
        }

        return implode(' ', $parts);
    }

    private function toArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value === null) {
            return [];
        }

        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
