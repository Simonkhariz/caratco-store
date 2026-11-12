<?php

declare(strict_types=1);

/**
 * Stand-in Redis server for the tests (no Redis needed on the test machine). Speaks RESP over
 * TCP and implements what the shop uses, following Redis's documented behaviour: AUTH (with
 * requirepass), SELECT, PING, GET, SET (EX/PX, NX/XX), DEL, EXISTS, INCR, EXPIRE, TTL, DBSIZE,
 * FLUSHDB, KEYS, QUIT. Keys expire like Redis's; values are kept in memory only.
 *
 *   php fake_redis.php <port>
 *   FAKE_REDIS_PASSWORD   password clients must send (none if empty)
 *   FAKE_REDIS_DIR        folder holding "mode": ok (default) or hang (accept, never answer)
 */

$port = (int) ($argv[1] ?? 6399);
$password = (string) getenv('FAKE_REDIS_PASSWORD');
$dir = (string) getenv('FAKE_REDIS_DIR');
$server = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $error);
if ($server === false) {
    fwrite(STDERR, "fake redis: {$error}\n");
    exit(1);
}
$clients = [];   // id => ['sock' => resource, 'buf' => string, 'auth' => bool, 'db' => int]
$data = [];      // db => key => ['v' => string, 'exp' => float|null]

$alive = static function (int $db, string $key) use (&$data): bool {
    if (!isset($data[$db][$key])) {
        return false;
    }
    $exp = $data[$db][$key]['exp'];
    if ($exp !== null && $exp <= microtime(true)) {
        unset($data[$db][$key]);
        return false;
    }
    return true;
};
$bulk = static fn (?string $s): string => $s === null ? "\$-1\r\n" : '$' . strlen($s) . "\r\n{$s}\r\n";
$int = static fn (int $n): string => ":{$n}\r\n";
$err = static fn (string $m): string => "-{$m}\r\n";

/** Parse one RESP command from $buf; null when it is not complete yet. */
$parse = static function (string &$buf): ?array {
    if ($buf === '' || $buf[0] !== '*') {
        $end = strpos($buf, "\r\n");
        if ($end === false) {
            return null;
        }
        $line = substr($buf, 0, $end);
        $buf = substr($buf, $end + 2);
        return preg_split('/\s+/', trim($line)) ?: [];
    }
    $pos = 0;
    $end = strpos($buf, "\r\n", $pos);
    if ($end === false) {
        return null;
    }
    $count = (int) substr($buf, 1, $end - 1);
    $pos = $end + 2;
    $args = [];
    for ($i = 0; $i < $count; $i++) {
        $end = strpos($buf, "\r\n", $pos);
        if ($end === false || $buf[$pos] !== '$') {
            return null;
        }
        $len = (int) substr($buf, $pos + 1, $end - $pos - 1);
        $pos = $end + 2;
        if (strlen($buf) < $pos + $len + 2) {
            return null;
        }
        $args[] = substr($buf, $pos, $len);
        $pos += $len + 2;
    }
    $buf = substr($buf, $pos);
    return $args;
};

while (true) {
    $read = [$server];
    foreach ($clients as $c) {
        $read[] = $c['sock'];
    }
    $write = $except = null;
    if (@stream_select($read, $write, $except, 1) === false) {
        continue;
    }
    foreach ($read as $sock) {
        if ($sock === $server) {
            $new = @stream_socket_accept($server, 0);
            if ($new !== false) {
                $clients[(int) $new] = ['sock' => $new, 'buf' => '', 'auth' => $password === '', 'db' => 0];
            }
            continue;
        }
        $id = (int) $sock;
        $chunk = @fread($sock, 65536);
        if ($chunk === false || $chunk === '') {
            @fclose($sock);
            unset($clients[$id]);
            continue;
        }
        $clients[$id]['buf'] .= $chunk;
        if ($dir !== '' && trim((string) @file_get_contents("{$dir}/mode")) === 'hang') {
            continue; // read, never answer
        }
        while (($args = $parse($clients[$id]['buf'])) !== null) {
            $cmd = strtoupper((string) ($args[0] ?? ''));
            $db = $clients[$id]['db'];
            if (!$clients[$id]['auth'] && $cmd !== 'AUTH' && $cmd !== 'QUIT') {
                $out = $err('NOAUTH Authentication required.');
            } else {
                $out = match ($cmd) {
                    'AUTH' => ($password === '' ? $err('ERR Client sent AUTH, but no password is set') : (($args[count($args) - 1] ?? '') === $password ? (function () use (&$clients, $id) { $clients[$id]['auth'] = true; return "+OK\r\n"; })() : $err('WRONGPASS invalid username-password pair or user is disabled.'))),
                    'SELECT' => (function () use (&$clients, $id, $args) { $clients[$id]['db'] = (int) ($args[1] ?? 0); return "+OK\r\n"; })(),
                    'PING' => "+PONG\r\n",
                    'GET' => $bulk($alive($db, (string) $args[1]) ? $data[$db][$args[1]]['v'] : null),
                    'SET' => (function () use (&$data, $db, $args, $alive, $bulk) {
                        $key = (string) $args[1];
                        $exp = null; $nx = $xx = false;
                        for ($i = 3; $i < count($args); $i++) {
                            $opt = strtoupper($args[$i]);
                            if ($opt === 'EX') { $exp = microtime(true) + (int) $args[++$i]; }
                            elseif ($opt === 'PX') { $exp = microtime(true) + ((int) $args[++$i]) / 1000; }
                            elseif ($opt === 'NX') { $nx = true; }
                            elseif ($opt === 'XX') { $xx = true; }
                        }
                        $exists = $alive($db, $key);
                        if (($nx && $exists) || ($xx && !$exists)) {
                            return $bulk(null);
                        }
                        $data[$db][$key] = ['v' => (string) $args[2], 'exp' => $exp];
                        return "+OK\r\n";
                    })(),
                    'DEL' => (function () use (&$data, $db, $args, $alive, $int) {
                        $n = 0;
                        foreach (array_slice($args, 1) as $k) {
                            if ($alive($db, $k)) { unset($data[$db][$k]); $n++; }
                        }
                        return $int($n);
                    })(),
                    'EXISTS' => $int(count(array_filter(array_slice($args, 1), fn ($k) => $alive($db, $k)))),
                    'INCR' => (function () use (&$data, $db, $args, $alive, $int, $err) {
                        $k = (string) $args[1];
                        $v = $alive($db, $k) ? $data[$db][$k]['v'] : '0';
                        if (!preg_match('/^-?\d+$/', $v)) { return $err('ERR value is not an integer or out of range'); }
                        $data[$db][$k] = ['v' => (string) ((int) $v + 1), 'exp' => $data[$db][$k]['exp'] ?? null];
                        return $int((int) $v + 1);
                    })(),
                    'EXPIRE' => (function () use (&$data, $db, $args, $alive, $int) {
                        if (!$alive($db, (string) $args[1])) { return $int(0); }
                        $data[$db][$args[1]]['exp'] = microtime(true) + (int) $args[2];
                        return $int(1);
                    })(),
                    'TTL' => $int(!$alive($db, (string) $args[1]) ? -2 : ($data[$db][$args[1]]['exp'] === null ? -1 : (int) ceil($data[$db][$args[1]]['exp'] - microtime(true)))),
                    'DBSIZE' => $int(count(array_filter(array_keys($data[$db] ?? []), fn ($k) => $alive($db, (string) $k)))),
                    'FLUSHDB' => (function () use (&$data, $db) { $data[$db] = []; return "+OK\r\n"; })(),
                    'KEYS' => (function () use (&$data, $db, $args, $alive, $bulk) {
                        $re = '/^' . str_replace('\\*', '.*', preg_quote((string) $args[1], '/')) . '$/s';
                        $keys = array_values(array_filter(array_map('strval', array_keys($data[$db] ?? [])), fn ($k) => $alive($db, $k) && preg_match($re, $k)));
                        return '*' . count($keys) . "\r\n" . implode('', array_map($bulk, $keys));
                    })(),
                    'QUIT' => "+OK\r\n",
                    default => $err("ERR unknown command '{$cmd}'"),
                };
            }
            @fwrite($sock, $out);
            if ($cmd === 'QUIT') {
                @fclose($sock);
                unset($clients[$id]);
                break;
            }
        }
    }
}
