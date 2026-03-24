<?php

declare(strict_types=1);

namespace App\Stripe;

use DomainException;

final class IgnoredStripeEventException extends DomainException
{
}
