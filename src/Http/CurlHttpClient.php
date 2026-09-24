<?php

declare(strict_types=1);

namespace Krafsys\PaymentManager\Http;

use Krafsys\PaymentManager\Contracts\HttpClientInterface;
use Krafsys\PaymentManager\Exceptions\GatewayTimeoutException;

/**
 * Deliberately dependency-free: uses ext-curl (bundled with virtually every
 * PHP install) instead of Guzzle/PSR-18, so installing this package never
 * pulls in another HTTP library or risks a version conflict with one your
 * app already uses.
 */
final class CurlHttpClient implements HttpClientInterface
{
    /** HTTP status codes worth retrying — connection-level issues are retried regardless. */
    private const RETRYABLE_STATUS_CODES = [429, 500, 502, 503, 504];

    public function __construct(
        private readonly string $gatewayLabel,
        private readonly int $connectTimeoutSeconds = 10,
        private readonly int $timeoutSeconds = 30,
        private readonly int $maxRetries = 2,
        private readonly int $retryDelayMilliseconds = 300
    ) {
    }

    public function request(string $method, string $url, array $headers = [], ?array $jsonBody = null): HttpResponse
    {
        $attempt = 0;
        $lastCurlError = '';

        while ($attempt <= $this->maxRetries) {
            $attempt++;

            [$response, $curlError] = $this->attempt($method, $url, $headers, $jsonBody);

            if ($response !== null) {
                if (!in_array($response->statusCode, self::RETRYABLE_STATUS_CODES, true) || $attempt > $this->maxRetries) {
                    return $response;
                }
            } else {
                $lastCurlError = $curlError;
            }

            if ($attempt <= $this->maxRetries) {
                usleep($this->retryDelayMilliseconds * 1000 * $attempt);
            }
        }

        if (isset($response) && $response !== null) {
            return $response;
        }

        throw GatewayTimeoutException::forGateway($this->gatewayLabel, $lastCurlError);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $jsonBody
     * @return array{0: ?HttpResponse, 1: string}
     */
    private function attempt(string $method, string $url, array $headers, ?array $jsonBody): array
    {
        $handle = curl_init();

        $formattedHeaders = ['Accept: application/json'];
        foreach ($headers as $key => $value) {
            $formattedHeaders[] = sprintf('%s: %s', $key, $value);
        }

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ];

        if ($jsonBody !== null) {
            $formattedHeaders[] = 'Content-Type: application/json';
            $options[CURLOPT_POSTFIELDS] = json_encode($jsonBody, JSON_THROW_ON_ERROR);
        }

        $options[CURLOPT_HTTPHEADER] = $formattedHeaders;

        curl_setopt_array($handle, $options);

        $body = curl_exec($handle);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        $statusCode = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);

        curl_close($handle);

        if ($errno !== 0 || $body === false) {
            return [null, $error !== '' ? $error : sprintf('cURL error %d', $errno)];
        }

        $decoded = json_decode((string) $body, true);

        return [
            new HttpResponse($statusCode, (string) $body, is_array($decoded) ? $decoded : []),
            '',
        ];
    }
}
