# Stripe -> wFirma integrator

Lekki integrator w PHP odbierający webhook Stripe i zapisujący sprzedaż w wFirma jako dokument oznaczony jako opłacony.
//Pamiętaj o ryzyku że wFirma może nie zezwalać na takie integracje oraz że możesz stracić dane 

## Co robi

- weryfikuje podpis `Stripe-Signature`
- obsługuje eventy `checkout.session.completed` i `payment_intent.succeeded`
- mapuje dane klienta i pozycji sprzedaży do payloadu `wFirma`
- tworzy fakturę przez `POST /invoices/add`
- oznacza import jako idempotentny po `event_id` i referencji płatności Stripe
- loguje błędy do pliku w `storage/logs/app.log`

## Wymagania

- PHP 8.2+
- Composer
- konto Stripe z webhookiem
- dostęp do API `wFirma`
- `appKey` od `wFirma`, który trzeba uzyskać osobno przez formularz / kontakt z `wFirma`

## Instalacja

```bash
cp .env.example .env
composer install
```

Uzupełnij w `.env`:

- `STRIPE_WEBHOOK_SECRET`
- `WFIRMA_ACCESS_KEY`
- `WFIRMA_SECRET_KEY`
- `WFIRMA_APP_KEY`
- opcjonalnie `WFIRMA_COMPANY_ID`

## Uruchomienie lokalne

```bash
composer serve
```

Aplikacja wystawia:

- `GET /health`
- `POST /webhooks/stripe`

## Uruchomienie na VPS bez serwera WWW

Najprostszy wariant na VPS to uruchomienie aplikacji na wbudowanym serwerze `PHP`, bez `Nginx` i bez `Apache`.

### 1. Zainstaluj wymagania

```bash
sudo apt update
sudo apt install -y php8.2 php8.2-curl php8.2-xml php8.2-mbstring composer unzip
```

### 2. Wgraj projekt i zainstaluj zależności

```bash
cp .env.example .env
composer install --no-dev --optimize-autoloader
```

Uzupełnij plik `.env` produkcyjnymi wartościami i ustaw:

```env
APP_ENV=prod
APP_DEBUG=false
```

### 3. Uruchom ręcznie

```bash
php -S 0.0.0.0:8080 -t public
```

Webhook będzie dostępny pod adresem:

- `http://ADRES_VPS:8080/health`
- `http://ADRES_VPS:8080/webhooks/stripe`

### 4. Uruchom jako usługę systemową

Aby proces działał po restarcie VPS, utwórz usługę `systemd`:

```bash
sudo nano /etc/systemd/system/wfirma-ksef.service
```

Wklej:

```ini
[Unit]
Description=Stripe to wFirma PHP Integrator
After=network.target

[Service]
User=www-data
Group=www-data
WorkingDirectory=/var/www/wfirma_ksef
ExecStart=/usr/bin/php -S 0.0.0.0:8080 -t public
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

Następnie uruchom:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now wfirma-ksef
sudo systemctl status wfirma-ksef
```

### 5. Otwórz port w firewallu

Jeżeli używasz `ufw`:

```bash
sudo ufw allow 8080/tcp
```

### 6. Skonfiguruj Stripe webhook

W panelu Stripe ustaw endpoint webhooka na:

```text
http://ADRES_VPS:8080/webhooks/stripe
```

Jeżeli chcesz używać webhooka produkcyjnie, zalecany jest jednak `HTTPS`, bo bez serwera WWW i certyfikatu SSL endpoint będzie działał wyłącznie po `HTTP`.

## Test webhooka Stripe

Przykład z Stripe CLI:

```bash
stripe listen --forward-to http://127.0.0.1:8080/webhooks/stripe
```

Następnie wykonaj przykładową płatność lub wyślij testowy event.

## Wymagane dane klienta

`wFirma` oczekuje kompletnych danych kontrahenta. Integrator pobiera je z adresu rozliczeniowego Stripe albo z `metadata`.

Obsługiwane klucze `metadata`:

- `wfirma_customer_name`
- `wfirma_street`
- `wfirma_zip`
- `wfirma_city`
- `wfirma_country`
- `wfirma_email`
- `wfirma_nip`
- `wfirma_item_name`
- `wfirma_description`
- `wfirma_vat_rate`
- `wfirma_currency`

Jeżeli Stripe nie dostarczy `name`, `street`, `zip` lub `city`, webhook zwróci błąd `422`, żeby nie zapisać niepełnych danych księgowych.

## Uwagi implementacyjne

Pierwsza wersja integratora korzysta z `POST /invoices/add` i przekazuje pola `paymentmethod` oraz `paymentdate`, co pozwala utworzyć sprzedaż już oznaczoną jako opłacona. Jeżeli Twoje konto `wFirma` wymaga innego sposobu rozliczenia płatności, można dołożyć drugi krok w kliencie `wFirma` bez przebudowy całej aplikacji.
