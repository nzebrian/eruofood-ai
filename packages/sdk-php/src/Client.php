<?php

declare(strict_types=1);

namespace EruoFood\Sdk;

/**
 * EruoFood Public API — PHP SDK (foundation).
 *
 * A minimal cURL-based client: API-key auth, configuration, typed errors and a
 * pagination helper. No framework or Guzzle dependency, so it drops into any
 * PHP 8.2+ project.
 */
final class Client
{
    private string $baseUrl;

    public function __construct(
        private readonly string $apiKey,
        string $baseUrl = 'https://api.eruofood.example/api/public/v1',
        private readonly int $timeoutSeconds = 10,
    ) {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('An API key is required.');
        }
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * GET a single resource; returns the unwrapped `data`.
     *
     * @param array<string, scalar> $query
     *
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        /** @var array{data?: array<string, mixed>} $body */
        $body = $this->request($path, $query);

        return $body['data'] ?? [];
    }

    /**
     * GET a paginated collection; returns the full `{ data, meta }` page.
     *
     * @param array<string, scalar> $query
     *
     * @return array{data: list<mixed>, meta: array<string, mixed>}
     */
    public function getPage(string $path, array $query = []): array
    {
        /** @var array{data: list<mixed>, meta: array<string, mixed>} $body */
        $body = $this->request($path, $query);

        return $body;
    }

    /**
     * Fetch every item across all pages.
     *
     * @param array<string, scalar> $query
     *
     * @return \Generator<int, mixed>
     */
    public function paginate(string $path, array $query = []): \Generator
    {
        $page = (int) ($query['page'] ?? 1);
        do {
            $result = $this->getPage($path, ['page' => $page] + $query);
            foreach ($result['data'] as $item) {
                yield $item;
            }
            $hasMore = (bool) ($result['meta']['pagination']['has_more'] ?? false);
            $page++;
        } while ($hasMore);
    }

    /**
     * @param array<string, scalar> $query
     *
     * @return array<string, mixed>
     */
    private function request(string $path, array $query): array
    {
        $url = $this->baseUrl.$path;
        if ($query !== []) {
            $url .= '?'.http_build_query($query);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer '.$this->apiKey,
                'Accept: application/json',
            ],
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new ApiException(0, 'network_error', $error);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        /** @var array<string, mixed> $body */
        $body = is_string($raw) && $raw !== '' ? (json_decode($raw, true) ?: []) : [];

        if ($status < 200 || $status >= 300) {
            /** @var array{code?: string, message?: string, details?: mixed} $err */
            $err = $body['error'] ?? [];
            throw new ApiException($status, $err['code'] ?? 'error', $err['message'] ?? 'Request failed', $err['details'] ?? null);
        }

        return $body;
    }
}
