<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Support\Response;

/**
 * Query-string values are single pieces of text. PHP turns "?search[]=x" or "?scope[a]=b" into
 * a list, and with strict types a list reaching trim() or a string parameter was a 500 (seen
 * from scanners in storage/logs/error.log). Such requests now get a clear 400 before any
 * controller runs. The colour and size filters may come as a list ("?colors[]=red&colors[]=blue"):
 * those are turned into the comma-separated text the catalogue reads.
 */
final class QueryStringMiddleware
{
    /** Query parameters that may be sent as a flat list. */
    private const LIST_PARAMS = ['colors', 'sizes'];

    public static function handle(): void
    {
        foreach ($_GET as $key => $value) {
            if (!is_array($value)) {
                continue;
            }
            $flat = in_array($key, self::LIST_PARAMS, true)
                && array_filter($value, static fn (mixed $v): bool => !is_scalar($v)) === [];
            if (!$flat) {
                Response::error('The query parameter "' . self::label((string) $key) . '" must be a single value.', 400, 'BAD_REQUEST');
            }
            $_GET[$key] = implode(',', array_map('strval', $value));
        }
    }

    /** The parameter name for the message, kept short and printable. */
    private static function label(string $key): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_\-]/', '', $key) ?? '';
        return substr($clean, 0, 40);
    }
}
