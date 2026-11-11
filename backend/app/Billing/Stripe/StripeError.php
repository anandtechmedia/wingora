<?php

namespace App\Billing\Stripe;

use Symfony\Component\HttpKernel\Exception\HttpException;

class StripeError extends HttpException
{
    public function __construct(string $message, int $status = 502)
    {
        parent::__construct($status, $message);
    }
}
