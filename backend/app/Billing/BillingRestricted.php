<?php

namespace App\Billing;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** A request refused by the organization's plan or subscription state. Rendered as 402 with a machine-readable `code`. */
class BillingRestricted extends HttpException
{
    public function __construct(public readonly string $reason, string $message, public readonly array $context = [])
    {
        parent::__construct(402, $message);
    }
}
