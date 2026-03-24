<?php

declare(strict_types=1);

namespace App\Config;

use App\Support\Env;

final class AppConfig
{
    public function __construct(
        public readonly string $appEnv,
        public readonly bool $appDebug,
        public readonly string $projectRoot,
        public readonly string $storagePath,
        public readonly string $logFile,
        public readonly string $idempotencyPath,
        public readonly string $stripeWebhookSecret,
        public readonly string $wfirmaApiBaseUrl,
        public readonly string $wfirmaAccessKey,
        public readonly string $wfirmaSecretKey,
        public readonly string $wfirmaAppKey,
        public readonly ?string $wfirmaCompanyId,
        public readonly string $wfirmaInvoiceType,
        public readonly string $wfirmaPaymentMethod,
        public readonly string $wfirmaDefaultVatRate,
        public readonly string $wfirmaDefaultUnit,
        public readonly string $wfirmaDefaultCurrency,
    ) {
    }

    public static function fromEnvironment(string $projectRoot): self
    {
        $storagePath = rtrim(Env::get('APP_STORAGE_PATH', $projectRoot . '/storage'), '/');

        return new self(
            appEnv: Env::get('APP_ENV', 'dev'),
            appDebug: Env::bool('APP_DEBUG', true),
            projectRoot: rtrim($projectRoot, '/'),
            storagePath: $storagePath,
            logFile: Env::get('APP_LOG_FILE', $storagePath . '/logs/app.log'),
            idempotencyPath: Env::get('APP_IDEMPOTENCY_PATH', $storagePath . '/idempotency'),
            stripeWebhookSecret: Env::required('STRIPE_WEBHOOK_SECRET'),
            wfirmaApiBaseUrl: rtrim(Env::get('WFIRMA_API_BASE_URL', 'https://api2.wfirma.pl'), '/'),
            wfirmaAccessKey: Env::required('WFIRMA_ACCESS_KEY'),
            wfirmaSecretKey: Env::required('WFIRMA_SECRET_KEY'),
            wfirmaAppKey: Env::required('WFIRMA_APP_KEY'),
            wfirmaCompanyId: Env::get('WFIRMA_COMPANY_ID'),
            wfirmaInvoiceType: Env::get('WFIRMA_INVOICE_TYPE', 'normal'),
            wfirmaPaymentMethod: Env::get('WFIRMA_PAYMENT_METHOD', 'transfer'),
            wfirmaDefaultVatRate: Env::get('WFIRMA_DEFAULT_VAT_RATE', 'zw'),
            wfirmaDefaultUnit: Env::get('WFIRMA_DEFAULT_UNIT', 'szt.'),
            wfirmaDefaultCurrency: strtoupper(Env::get('WFIRMA_DEFAULT_CURRENCY', 'PLN') ?? 'PLN'),
        );
    }
}
