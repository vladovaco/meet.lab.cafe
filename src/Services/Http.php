<?php
declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;

/**
 * Guzzle klient s dlhými limitmi pre volania AI API (prepis aj analýza môžu bežať minúty
 * a streamované odpovede môžu mať dlhé tiché úseky). Bez explicitného timeoutu by PHP stream
 * na hostingu vypršal po default_socket_timeout (typicky 60 s) s chybou "Unable to read from stream".
 */
final class Http
{
    /** @param array<string,mixed> $extra */
    public static function client(int $timeoutSeconds = 1800, array $extra = []): Client
    {
        return new Client(array_merge([
            'timeout'         => $timeoutSeconds,
            'read_timeout'    => $timeoutSeconds,
            'connect_timeout' => 30,
            'http_errors'     => false,
        ], $extra));
    }
}
