<?php

namespace App\Security\Sso;

use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * SSRF guard for calls to customer-configured identity providers: HTTPS only, public addresses only,
 * no redirects, and the connection is pinned to the address that was checked (no DNS rebinding).
 */
final class OutboundHttp
{
    /** @param (Closure(string): list<string>)|null $resolver */
    public function __construct(private readonly ?Closure $resolver = null) {}

    public function to(string $url): PendingRequest
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? null;
        if (($parts['scheme'] ?? null) !== 'https' || ! $host || isset($parts['user']) || isset($parts['pass'])) {
            throw new SsoError('url_not_https');
        }

        $ips = ($this->resolver ?? fn (string $h) => gethostbynamel($h) ?: [])($host);
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new SsoError('url_not_public');
            }
        }
        if ($ips === []) {
            throw new SsoError('url_unresolvable');
        }

        $port = $parts['port'] ?? 443;

        return Http::timeout(10)->acceptJson()->withOptions([
            'allow_redirects' => false,
            'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ips[0]}"]],
        ]);
    }
}
