<?php

declare(strict_types=1);

namespace App\WFirma;

use App\Config\AppConfig;
use App\Support\FileLogger;

final class WFirmaClient
{
    public function __construct(
        private readonly AppConfig $config,
        private readonly FileLogger $logger,
    ) {
    }

    public function createPaidInvoice(array $payload): array
    {
        $response = $this->request('POST', '/invoices/add', $payload);
        $statusCode = $this->extractStatusCode($response);

        if ($statusCode !== 'OK') {
            throw new WFirmaApiException(
                message: sprintf('wFirma zwróciła status %s podczas tworzenia faktury.', $statusCode ?? 'UNKNOWN'),
                responseBody: $response,
            );
        }

        return $response;
    }

    public function extractInvoiceId(array $response): ?string
    {
        $invoice = $this->firstInvoiceNode($response);

        if ($invoice === null) {
            return null;
        }

        $id = $invoice['id'] ?? null;

        return $id === null ? null : (string) $id;
    }

    private function request(string $method, string $path, array $payload): array
    {
        $query = [
            'inputFormat' => 'json',
            'outputFormat' => 'json',
        ];

        if ($this->config->wfirmaCompanyId !== null && $this->config->wfirmaCompanyId !== '') {
            $query['company_id'] = $this->config->wfirmaCompanyId;
        }

        $url = sprintf('%s%s?%s', $this->config->wfirmaApiBaseUrl, $path, http_build_query($query));
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($body === false) {
            throw new WFirmaApiException('Nie udało się zakodować payloadu JSON do wFirma.');
        }

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'accessKey: ' . $this->config->wfirmaAccessKey,
                'secretKey: ' . $this->config->wfirmaSecretKey,
                'appKey: ' . $this->config->wfirmaAppKey,
            ],
        ]);

        $rawResponse = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpStatusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($rawResponse === false) {
            throw new WFirmaApiException(sprintf('Nie udało się połączyć z API wFirma: %s', $curlError), httpStatusCode: $httpStatusCode);
        }

        $decoded = json_decode($rawResponse, true);

        if (! is_array($decoded)) {
            throw new WFirmaApiException(
                message: 'wFirma zwróciła odpowiedź w nieoczekiwanym formacie.',
                responseBody: ['raw' => $rawResponse],
                httpStatusCode: $httpStatusCode,
            );
        }

        if ($httpStatusCode >= 400) {
            $this->logger->error('wFirma HTTP error', ['status' => $httpStatusCode, 'body' => $decoded]);

            throw new WFirmaApiException(
                message: sprintf('wFirma zwróciła błąd HTTP %d.', $httpStatusCode),
                responseBody: $decoded,
                httpStatusCode: $httpStatusCode,
            );
        }

        return $decoded;
    }

    private function extractStatusCode(array $response): ?string
    {
        $candidates = [
            $response['api']['status']['code'] ?? null,
            $response['status']['code'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_scalar($candidate) && trim((string) $candidate) !== '') {
                return trim((string) $candidate);
            }
        }

        return null;
    }

    private function firstInvoiceNode(array $response): ?array
    {
        $invoice = $response['api']['invoices']['invoice'] ?? $response['invoices']['invoice'] ?? null;

        if (is_array($invoice) && isset($invoice['id'])) {
            return $invoice;
        }

        if (is_array($invoice)) {
            foreach ($invoice as $item) {
                if (is_array($item) && isset($item['id'])) {
                    return $item;
                }
            }
        }

        return null;
    }
}
