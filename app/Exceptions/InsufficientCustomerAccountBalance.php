<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientCustomerAccountBalance extends RuntimeException
{
    public function __construct(
        public readonly float $available,
        public readonly float $required,
        public readonly float $deficit,
        string $message = 'Insufficient account balance.'
    ) {
        parent::__construct($message);
    }

    /**
     * @return array{available: float, required: float, deficit: float, message: string}
     */
    public function toArray(): array
    {
        return [
            'available' => $this->available,
            'required' => $this->required,
            'deficit' => $this->deficit,
            'message' => sprintf(
                'Insufficient account balance. Customer has %s available, but this requires %s. Additional %s is required.',
                number_format($this->available, 2),
                number_format($this->required, 2),
                number_format($this->deficit, 2),
            ),
        ];
    }
}
