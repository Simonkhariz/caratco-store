<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * A 422 whose answer also says which fields are wrong (`details`: field => message).
 */
final class ValidationFailed extends InvalidArgumentException
{
    /** @param array<string, string> $details */
    public function __construct(string $message, public readonly array $details)
    {
        parent::__construct($message);
    }
}
