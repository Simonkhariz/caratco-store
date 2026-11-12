<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * A refused or failed Resend send; $permanent means retrying the same email cannot succeed.
 * $rateLimited: too many requests per second (not a daily or monthly quota), so the email itself
 * is fine and only the sending pace has to drop.
 */
final class ResendError extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus, public readonly bool $permanent, public readonly bool $rateLimited = false)
    {
        parent::__construct($message);
    }
}
