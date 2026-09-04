<?php

namespace App\Exceptions;

use RuntimeException;

class SubscriptionRestrictionException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $restriction,
        public readonly int $statusCode = 422,
        public readonly array $details = []
    ) {
        parent::__construct($message);
    }

    public function render()
    {
        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
            'restriction' => $this->restriction,
            'data' => $this->details,
        ], $this->statusCode);
    }
}
