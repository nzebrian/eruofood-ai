<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Infrastructure\Webhook;

use EruoFood\PublicApi\Application\Port\WebhookUrlGuard;
use EruoFood\PublicApi\Domain\Exception\WebhookDestinationRejected;
use Throwable;

/**
 * The SSRF/egress policy for webhook destinations. It parses the URL, enforces
 * the scheme/port/credentials rules, then resolves the host and requires every
 * resolved address (IPv4 and IPv6) to be publicly routable — refusing loopback,
 * private, link-local, CGNAT and other reserved ranges. Because it re-resolves
 * on every call, it is invoked both at registration and immediately before each
 * delivery, which is what closes the DNS-rebinding (TOCTOU) window in code.
 *
 * NOTE: application-level DNS validation cannot by itself defeat a resolver that
 * returns a different address at connection time. Production must therefore pair
 * this with infrastructure egress controls (see WEBHOOKS.md): outbound webhook
 * traffic should egress through a proxy/network policy that also blocks the
 * internal ranges, so the two layers agree.
 */
final readonly class NetworkWebhookUrlGuard implements WebhookUrlGuard
{
    /**
     * @param list<string> $allowedSchemes lowercased, e.g. ['https']
     * @param list<int> $allowedPorts e.g. [443]
     * @param list<string> $allowedHosts optional explicit allowlist; if non-empty, the host must match
     * @param list<array{0:string,1:int}> $blockedCidrs additional CIDR blocks (base IP + prefix) to reject
     */
    public function __construct(
        private array $allowedSchemes = ['https'],
        private bool $enforceHttps = true,
        private array $allowedPorts = [443, 80],
        private bool $blockPrivateNetworks = true,
        private array $allowedHosts = [],
        private array $blockedCidrs = [],
    ) {
    }

    public function assertAllowed(string $url): void
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw WebhookDestinationRejected::malformed();
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (! in_array($scheme, $this->allowedSchemes, true)) {
            throw WebhookDestinationRejected::scheme($scheme);
        }
        if ($this->enforceHttps && $scheme !== 'https') {
            throw WebhookDestinationRejected::insecure();
        }

        // Credentials in the URL (user:pass@host) are a common SSRF/exfil vector.
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw WebhookDestinationRejected::credentials();
        }

        $host = $this->normaliseHost((string) $parts['host']);

        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        if ($this->allowedPorts !== [] && ! in_array($port, $this->allowedPorts, true)) {
            throw WebhookDestinationRejected::port($port);
        }

        if ($this->allowedHosts !== [] && ! $this->hostIsAllowlisted($host)) {
            throw WebhookDestinationRejected::privateAddress($host);
        }

        if (! $this->blockPrivateNetworks) {
            return;
        }

        foreach ($this->resolve($host) as $ip) {
            if (! $this->isPublicIp($ip)) {
                throw WebhookDestinationRejected::privateAddress($host);
            }
        }
    }

    public function isAllowed(string $url): bool
    {
        try {
            $this->assertAllowed($url);

            return true;
        } catch (WebhookDestinationRejected) {
            return false;
        }
    }

    /** Strip brackets from IPv6 literals and lower-case the host. */
    private function normaliseHost(string $host): string
    {
        $host = trim($host);
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        return strtolower($host);
    }

    private function hostIsAllowlisted(string $host): bool
    {
        foreach ($this->allowedHosts as $allowed) {
            $allowed = strtolower(trim($allowed));
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve a host to the set of IPs it currently points at. IP literals are
     * returned as-is; names are resolved for both A and AAAA records.
     *
     * @return list<string>
     */
    private function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $ips = [];

        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            foreach ($v4 as $ip) {
                $ips[] = $ip;
            }
        }

        try {
            $records = @dns_get_record($host, DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                        $ips[] = $record['ipv6'];
                    }
                }
            }
        } catch (Throwable) {
            // AAAA lookup is best-effort; A-record resolution above is primary.
        }

        $ips = array_values(array_unique($ips));
        if ($ips === []) {
            throw WebhookDestinationRejected::unresolvable($host);
        }

        return $ips;
    }

    /**
     * A publicly routable address: not private, not reserved, and not in one of
     * the explicitly blocked ranges (loopback, link-local, CGNAT, IPv6 ULA, etc.).
     */
    private function isPublicIp(string $ip): bool
    {
        // PHP's filter flags reject RFC1918 private ranges and the standard
        // reserved ranges (loopback, link-local, documentation, benchmarking…).
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        // Defence in depth: explicitly reject ranges the flags may miss
        // (e.g. CGNAT 100.64/10, the 0.0.0.0/8 "this host" block, IPv6 ULA/link-local).
        foreach ($this->specialRanges() as [$base, $prefix]) {
            if ($this->ipInCidr($ip, $base, $prefix)) {
                return false;
            }
        }
        foreach ($this->blockedCidrs as [$base, $prefix]) {
            if ($this->ipInCidr($ip, $base, $prefix)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<array{0:string,1:int}>
     */
    private function specialRanges(): array
    {
        return [
            ['0.0.0.0', 8],       // "this host"
            ['127.0.0.0', 8],     // loopback
            ['10.0.0.0', 8],      // private
            ['172.16.0.0', 12],   // private
            ['192.168.0.0', 16],  // private
            ['169.254.0.0', 16],  // link-local
            ['100.64.0.0', 10],   // CGNAT
            ['192.0.0.0', 24],    // IETF protocol assignments
            ['198.18.0.0', 15],   // benchmarking
            ['::1', 128],         // IPv6 loopback
            ['::', 128],          // IPv6 unspecified
            ['fc00::', 7],        // IPv6 unique local
            ['fe80::', 10],       // IPv6 link-local
            ['::ffff:0:0', 96],   // IPv4-mapped IPv6
        ];
    }

    private function ipInCidr(string $ip, string $base, int $prefix): bool
    {
        $ipBin = @inet_pton($ip);
        $baseBin = @inet_pton($base);
        if ($ipBin === false || $baseBin === false || strlen($ipBin) !== strlen($baseBin)) {
            return false;
        }

        $bytes = intdiv($prefix, 8);
        $remainder = $prefix % 8;

        if ($bytes > 0 && strncmp($ipBin, $baseBin, $bytes) !== 0) {
            return false;
        }
        if ($remainder === 0) {
            return true;
        }

        $mask = chr((0xFF << (8 - $remainder)) & 0xFF);

        return (ord($ipBin[$bytes]) & ord($mask)) === (ord($baseBin[$bytes]) & ord($mask));
    }
}
