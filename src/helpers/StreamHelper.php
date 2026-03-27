<?php

namespace samuelreichor\coPilot\helpers;

use GuzzleHttp\Client;

/**
 * Handles Server-Sent Events (SSE) streaming with buffered line parsing.
 *
 * Encapsulates the common pattern of connecting to an SSE endpoint,
 * buffering chunked data, splitting into lines, and parsing JSON payloads.
 */
final class StreamHelper
{
    /**
     * Sends a POST request to an SSE endpoint and processes events line-by-line.
     *
     * @param Client $client Guzzle HTTP client
     * @param string $url API endpoint URL
     * @param array<string, string> $headers HTTP headers
     * @param array<string, mixed> $payload Request payload (will be JSON-encoded)
     * @param callable(string $eventType, array $json): void $onEvent Called for each parsed SSE event
     * @throws \Throwable Re-throws any transport or processing error
     */
    public static function stream(
        Client $client,
        string $url,
        array $headers,
        array $payload,
        callable $onEvent,
    ): void {
        $buffer = '';
        $currentEventType = '';

        $processLine = function(string $line) use (&$currentEventType, $onEvent): void {
            $line = trim($line);

            if ($line === '') {
                return;
            }

            // Some proxies (e.g. Langdock) wrap SSE lines in brackets: [event: ...]
            if (str_starts_with($line, '[')) {
                $line = substr($line, 1);
            }
            if (str_ends_with($line, ']')) {
                $line = substr($line, 0, -1);
            }
            $line = trim($line);

            if (str_starts_with($line, 'event: ')) {
                $currentEventType = substr($line, 7);
                return;
            }

            if (!str_starts_with($line, 'data: ')) {
                return;
            }

            $json = json_decode(substr($line, 6), true);
            if (!is_array($json)) {
                return;
            }

            $onEvent($currentEventType, $json);
        };

        $headers['Content-Type'] = 'application/json';

        $client->post($url, [
            'headers' => $headers,
            'body' => json_encode($payload),
            'timeout' => 120,
            'curl' => [
                CURLOPT_WRITEFUNCTION => function($ch, $data) use (&$buffer, $processLine) {
                    $buffer .= $data;
                    $lines = explode("\n", $buffer);
                    $buffer = (string) array_pop($lines);

                    foreach ($lines as $line) {
                        $processLine($line);
                    }

                    return strlen($data);
                },
            ],
        ]);

        // Flush remaining buffer
        if (trim($buffer) !== '') {
            Logger::warning('SSE stream: flushing unparsed buffer remainder (' . strlen($buffer) . ' bytes)');
            $processLine($buffer);
        }
    }
}
