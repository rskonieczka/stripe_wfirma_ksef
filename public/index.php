<?php

declare(strict_types=1);

use App\Application\StripeWebhookHandler;
use App\Http\JsonResponse;
use App\Storage\IdempotencyStore;
use App\Stripe\IgnoredStripeEventException;
use App\Stripe\StripeSaleDataMapper;
use App\Stripe\StripeWebhookVerifier;
use App\Support\FileLogger;
use App\WFirma\WFirmaApiException;
use App\WFirma\WFirmaClient;

$config = require dirname(__DIR__) . '/bootstrap.php';
$logger = new FileLogger($config->logFile);
$idempotencyStore = new IdempotencyStore($config->idempotencyPath);
$handler = new StripeWebhookHandler(
    verifier: new StripeWebhookVerifier($config->stripeWebhookSecret),
    mapper: new StripeSaleDataMapper($config),
    wfirmaClient: new WFirmaClient($config, $logger),
    idempotencyStore: $idempotencyStore,
    logger: $logger,
);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($method === 'GET' && $path === '/health') {
    JsonResponse::send(200, [
        'ok' => true,
        'app' => 'stripe-wfirma-integrator',
        'env' => $config->appEnv,
    ]);

    return;
}

if ($method === 'POST' && $path === '/webhooks/stripe') {
    $payload = file_get_contents('php://input') ?: '';
    $signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? null;

    try {
        $result = $handler->handle($payload, $signature);
        JsonResponse::send(200, ['ok' => true] + $result);
    } catch (\Stripe\Exception\SignatureVerificationException | \UnexpectedValueException $exception) {
        $logger->warning('Invalid Stripe webhook signature', ['error' => $exception->getMessage()]);
        JsonResponse::send(400, ['ok' => false, 'error' => 'invalid_signature']);
    } catch (IgnoredStripeEventException $exception) {
        $logger->info('Stripe event ignored', ['error' => $exception->getMessage()]);
        JsonResponse::send(200, ['ok' => true, 'status' => 'ignored', 'message' => $exception->getMessage()]);
    } catch (\DomainException $exception) {
        $logger->warning('Stripe payload rejected for business reasons', ['error' => $exception->getMessage()]);
        JsonResponse::send(422, ['ok' => false, 'error' => 'invalid_payload', 'message' => $exception->getMessage()]);
    } catch (WFirmaApiException $exception) {
        $logger->error('wFirma import failed', [
            'error' => $exception->getMessage(),
            'response' => $exception->responseBody(),
            'http_status' => $exception->httpStatusCode(),
        ]);
        JsonResponse::send(502, ['ok' => false, 'error' => 'wfirma_error', 'message' => $exception->getMessage()]);
    } catch (Throwable $exception) {
        $logger->error('Unexpected webhook failure', ['error' => $exception->getMessage()]);
        JsonResponse::send(500, [
            'ok' => false,
            'error' => 'internal_error',
            'message' => $config->appDebug ? $exception->getMessage() : 'Wewnętrzny błąd serwera.',
        ]);
    }

    return;
}

JsonResponse::send(404, ['ok' => false, 'error' => 'not_found']);
