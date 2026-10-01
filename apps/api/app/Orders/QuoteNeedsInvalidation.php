<?php

declare(strict_types=1);

namespace App\Orders;

use RuntimeException;

final class QuoteNeedsInvalidation extends RuntimeException
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $quoteId,
    ) {
        parent::__construct('quote requires invalidation');
    }
}
