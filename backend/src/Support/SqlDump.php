<?php

declare(strict_types=1);

namespace App\Support;

use mysqli;
use RuntimeException;

/**
 * A database dump written by PHP alone, for hosts that do not let PHP run mysqldump (shared and
 * cPanel hosting often disable proc_open/exec). The output is plain SQL that scripts/
 * restore_database.php, phpMyAdmin or the mysql client can load.
 *
 * Like mysqldump --single-transaction: every table is read inside one consistent snapshot, so
 * orders taken while the dump runs never leave half an order in the backup, and the shop is not
 * locked. Each statement ends a line with ";" and values never contain a raw line break (they are
 * escaped), so a restore can split statements line by line. Tables only: the shop has no views,
 * triggers or stored routines.
 */
final class SqlDump
{
    /** Rows are grouped into INSERT statements of about this size (well under max_allowed_packet). */
    private const STATEMENT_BYTES = 512 * 1024;
    /** MySQL's "binary" character set number: such values are written as hex. */
    private const BINARY_CHARSET = 63;

    /**
     * Dump every table of $conn's current database through $write (called with each piece of
     * SQL). $conn should be a connection of its own: the dump opens a transaction on it.
     *
     * $keepRows limits the rows, for scripts/export_for_host.php: table name => WHERE condition
     * ('' for all its rows). A table not listed is written with its structure only, and starts
     * counting from 1 again. Null (the default, used by every backup) keeps everything.
     * $replace writes a fixed value instead of what a column holds, so the real value never
     * reaches the file: table name => [column => value or null].
     *
     * @param callable(string): void $write
     * @param array<string, string>|null $keepRows
     * @param array<string, array<string, ?string>> $replace
     * @return array<string, int> rows written per table
     */
    public static function dump(mysqli $conn, callable $write, ?array $keepRows = null, array $replace = []): array
    {
        $database = (string) $conn->query('SELECT DATABASE()')->fetch_row()[0];
        if ($database === '') {
            throw new RuntimeException('No database selected.');
        }
        $tables = [];
        foreach ($conn->query("SHOW FULL TABLES WHERE `Table_type` = 'BASE TABLE'")->fetch_all() as $row) {
            $tables[] = (string) $row[0];
        }

        $conn->query('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $conn->query('START TRANSACTION WITH CONSISTENT SNAPSHOT');

        $write(sprintf("-- Shop.co database dump (written by PHP) of `%s`, %s\n", $database, date('c')));
        $write("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\nSET time_zone='+00:00';\n\n");
        // Read TIMESTAMP values in UTC, matching the time_zone line above (DATETIME is unaffected).
        $conn->query("SET time_zone = '+00:00'");

        $counts = [];
        try {
            foreach ($tables as $table) {
                $quoted = self::name($table);
                $create = (string) $conn->query("SHOW CREATE TABLE {$quoted}")->fetch_row()[1];
                $structureOnly = $keepRows !== null && !array_key_exists($table, $keepRows);
                if ($structureOnly) {
                    $create = (string) preg_replace('/ AUTO_INCREMENT=\d+/', '', $create);
                }
                $write("DROP TABLE IF EXISTS {$quoted};\n{$create};\n");
                $counts[$table] = $structureOnly ? 0 : self::rows($conn, $table, $write, $keepRows[$table] ?? '', $replace[$table] ?? []);
                $write("\n");
            }
            $conn->query('COMMIT');
        } catch (\Throwable $e) {
            $conn->query('ROLLBACK');
            throw $e;
        }

        $write("SET FOREIGN_KEY_CHECKS=1;\nSET UNIQUE_CHECKS=1;\n");
        $write(sprintf("-- Dump completed %s: %d tables, %d rows\n", date('c'), count($counts), array_sum($counts)));
        return $counts;
    }

    /** @param callable(string): void $write */
    private static function rows(mysqli $conn, string $table, callable $write, string $where = '', array $replace = []): int
    {
        $quoted = self::name($table);
        // Unbuffered: rows stream from the server, so a large table never fills PHP's memory.
        $result = $conn->query("SELECT * FROM {$quoted}" . ($where === '' ? '' : " WHERE {$where}"), MYSQLI_USE_RESULT);
        $fields = $result->fetch_fields();
        $columns = implode(', ', array_map(static fn ($f): string => self::name((string) $f->name), $fields));
        $binary = array_map(static fn ($f): bool => (int) $f->charsetnr === self::BINARY_CHARSET && self::isByteString((int) $f->type), $fields);

        $prefix = "INSERT INTO {$quoted} ({$columns}) VALUES ";
        $batch = '';
        $count = 0;
        while (($row = $result->fetch_row()) !== null) {
            $values = [];
            foreach ($row as $i => $value) {
                $column = (string) $fields[$i]->name;
                if (array_key_exists($column, $replace)) {
                    $value = $replace[$column];
                }
                if ($value === null) {
                    $values[] = 'NULL';
                } elseif ($binary[$i]) {
                    $values[] = $value === '' ? "''" : '0x' . bin2hex((string) $value);
                } else {
                    $values[] = "'" . $conn->real_escape_string((string) $value) . "'";
                }
            }
            $batch .= ($batch === '' ? $prefix : ',') . '(' . implode(',', $values) . ')';
            $count++;
            if (strlen($batch) >= self::STATEMENT_BYTES) {
                $write($batch . ";\n");
                $batch = '';
            }
        }
        $result->free();
        if ($batch !== '') {
            $write($batch . ";\n");
        }
        return $count;
    }

    /**
     * Binary strings and blobs hold raw bytes, written as hex. Numbers, dates and JSON also report
     * the binary character set but are written as ordinary values (hex would not load into JSON).
     */
    private static function isByteString(int $type): bool
    {
        return in_array($type, [
            MYSQLI_TYPE_STRING, MYSQLI_TYPE_VAR_STRING, MYSQLI_TYPE_TINY_BLOB, MYSQLI_TYPE_BLOB,
            MYSQLI_TYPE_MEDIUM_BLOB, MYSQLI_TYPE_LONG_BLOB, MYSQLI_TYPE_BIT, MYSQLI_TYPE_GEOMETRY,
        ], true);
    }

    private static function name(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
