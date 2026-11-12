<?php

declare(strict_types=1);

namespace App\Config;

use RuntimeException;

/**
 * backend/config/shop.json is missing or is not valid JSON. The message is written for the shop's
 * owner or developer and is safe to show: it names the file and what is wrong, nothing else.
 */
final class ShopConfigError extends RuntimeException
{
}
