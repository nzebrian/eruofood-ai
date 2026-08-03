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
     * POST a resource; returns the unwrapped `data`.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public function post(string $path, array $body = []): array
    {
        /** @var array{data?: array<string, mixed>} $result */
        $result = $this->request($path, [], 'POST', $body);

        return $result['data'] ?? [];
    }

    // --- Convenience resource methods (thin wrappers) ---

    /** @param array<string, scalar> $query @return array{data: list<mixed>, meta: array<string, mixed>} */
    public function restaurants(array $query = []): array { return $this->getPage('/restaurants', $query); }

    /** @return array<string, mixed> */
    public function restaurant(string $slug): array { return $this->get('/restaurants/'.rawurlencode($slug)); }

    /** @return array<string, mixed> */
    public function restaurantMenu(string $id): array { return $this->get('/restaurants/'.rawurlencode($id).'/menu'); }

    /** @param array<string, scalar> $query @return array{data: list<mixed>, meta: array<string, mixed>} */
    public function products(array $query = []): array { return $this->getPage('/products', $query); }

    /** @return array<string, mixed> */
    public function product(string $slug): array { return $this->get('/products/'.rawurlencode($slug)); }

    /** @return array<string, mixed> */
    public function productCategories(): array { return $this->get('/product-categories'); }

    /** @param array<string, scalar> $query @return array{data: list<mixed>, meta: array<string, mixed>} */
    public function nutritionItems(array $query = []): array { return $this->getPage('/nutrition', $query); }

    /** @return array<string, mixed> */
    public function nutritionItem(string $id): array { return $this->get('/nutrition/'.rawurlencode($id)); }

    /** @param array<string, scalar> $query @return array<string, mixed> */
    public function search(array $query = []): array { return $this->get('/search', $query); }

    /** @return array{data: list<mixed>, meta: array<string, mixed>} */
    public function orders(array $query = []): array { return $this->getPage('/orders', $query); }

    /** @return array<string, mixed> */
    public function order(string $id): array { return $this->get('/orders/'.rawurlencode($id)); }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    public function createOrder(array $body = []): array { return $this->post('/orders', $body); }

    /** @return array<string, mixed> */
    public function cancelOrder(string $id): array { return $this->post('/orders/'.rawurlencode($id).'/cancel'); }

    /**
     * @param array<string, scalar> $query
     * @param array<string, mixed>  $jsonBody
     *
     * @return array<string, mixed>
     */
    private function request(string $path, array $query, string $method = 'GET', array $jsonBody = []): array
    {
        $url = $this->baseUrl.$path;
        if ($query !== []) {
            $url .= '?'.http_build_query($query);
        }

        $headers = [
            'Authorization: Bearer '.$this->apiKey,
            'Accept: application/json',
        ];
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
        ];
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = json_encode($jsonBody, JSON_UNESCAPED_SLASHES) ?: '{}';
            $headers[] = 'Content-Type: application/json';
        }
        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $options);
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
