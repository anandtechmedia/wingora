<?php

namespace App\Security\Sso;

use RuntimeException;

/** A failed single sign-on step. The reason is logged as a security event and never shown to the user. */
final class SsoError extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
