<?php

declare(strict_types=1);

namespace Krafsys\PaymentManager\Tests\Fakes;

use Krafsys\PaymentManager\Contracts\HttpClientInterface;
use Krafsys\PaymentManager\Http\HttpResponse;
use RuntimeException;

final class FakeHttpClient implements HttpClientInterface
{
    /** @var HttpResponse[] */
    private array $queue = [];

    /** @var array<int, array{method: string, url: string, headers: array<string, string>, body: ?array<string, mixed>}> */
    public array $recordedRequests = [];

    public function queueJson(int $statusCode, array $json): self
    {
        $this->queue[] = new HttpResponse($statusCode, json_encode($json, JSON_THROW_ON_ERROR), $json);

        return $this;
    }

    public function request(string $method, string $url, array $headers = [], ?array $jsonBody = null): HttpResponse
    {
        $this->recordedRequests[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $jsonBody,
        ];

        if (count($this->queue) === 0) {
            throw new RuntimeException(sprintf('FakeHttpClient: no queued response left for %s %s', $method, $url));
        }

        return array_shift($this->queue);
    }

    public function lastRequest(): ?array
    {
        return $this->recordedRequests[count($this->recordedRequests) - 1] ?? null;
    }
}
