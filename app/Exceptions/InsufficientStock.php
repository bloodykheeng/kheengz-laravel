<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Asked for more than a location has free: holding an order's items, sending out more than is there,
 * or counting below what orders already hold. Carries what is available, for the message.
 */
class InsufficientStock extends RuntimeException
{
    public function __construct(public readonly string $available, string $message)
    {
        parent::__construct($message);
    }
}
