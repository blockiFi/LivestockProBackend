<?php

namespace App\Exceptions;

use RuntimeException;

/** A sale asks for more eggs than one or more batches have in stock. */
class EggStockExceeded extends RuntimeException
{
    public function __construct(string $message, public array $details = [])
    {
        parent::__construct($message);
    }
}
