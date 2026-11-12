<?php

declare(strict_types=1);

namespace App\Storage;

use RuntimeException;

/** Storing, reading or removing a picture failed (the message is safe to log; never shown raw). */
final class StorageError extends RuntimeException
{
}
