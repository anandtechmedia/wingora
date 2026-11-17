<?php

namespace App\Security\Sso;

use App\Models\SsoConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;

/**
 * OpenID Connect authorization-code flow with PKCE, without a third-party library.
 * ID tokens are accepted only when RS256-signed by a key from the provider's JWKS, and their
 * issuer, audience, expiry and nonce all match.
 */
final class OidcClient
{
    private const LEEWAY = 60;

    public function __construct(private readonly OutboundHttp $http) {}

    /** @return array{issuer: string, authorization_endpoint: string, token_endpoint: string, jwks_uri: string, token_endpoint_auth_methods_supported?: list<string>} */
    public function discover(string $issuer, bool $fresh = false): array
    {
        $key = 'oidc:discovery:'.sha1($issuer);
        if (! $fresh && is_array($cached = Cache::get($key))) {
            return $cached;
        }

        $doc = $this->getJson(rtrim($issuer, '/').'/.well-known/openid-configuration');
        if (($doc['issuer'] ?? null) !== $issuer) {
            throw new SsoError('issuer_mismatch');
        }
        foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $field) {
            if (! is_string($doc[$field] ?? null) || ! str_starts_with($doc[$field], 'https://')) {
                throw new SsoError('discovery_incomplete');
            }
        }
        $doc = array_intersect_key($doc, array_flip(['issuer', 'authorization_endpoint', 'token_endpoint', 'jwks_uri', 'token_endpoint_auth_methods_supported']));
        Cache::put($key, $doc, 3600);

        return $doc;
    }

    public function authorizationUrl(SsoConnection $connection, string $redirectUri, string $state, string $nonce, string $verifier): string
    {
        $endpoint = $this->discover($connection->issuer)['authorization_endpoint'];

        return $endpoint.(str_contains($endpoint, '?') ? '&' : '?').http_build_query([
            'response_type' => 'code',
            'client_id' => $connection->client_id,
            'redirect_uri' => $redirectUri,
            'scope' => 'openid email profile',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => self::b64url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** Exchanges the authorization code and returns the verified ID token claims. */
    public function exchange(SsoConnection $connection, string $code, string $verifier, string $redirectUri, string $nonce): array
    {
        $doc = $this->discover($connection->issuer);
        $form = ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirectUri, 'code_verifier' => $verifier];
        $methods = $doc['token_endpoint_auth_methods_supported'] ?? ['client_secret_basic'];
        $request = $this->http->to($doc['token_endpoint'])->asForm();

        if (in_array('client_secret_basic', $methods, true)) {
            $request = $request->withBasicAuth(rawurlencode($connection->client_id), rawurlencode($connection->client_secret));
        } else {
            $form += ['client_id' => $connection->client_id, 'client_secret' => $connection->client_secret];
        }

        try {
            $response = $request->post($doc['token_endpoint'], $form);
        } catch (ConnectionException) {
            throw new SsoError('token_unreachable');
        }
        $token = $response->successful() ? $response->json('id_token') : null;
        if (! is_string($token)) {
            throw new SsoError('token_rejected');
        }

        return $this->verify($token, $connection, $doc, $nonce);
    }

    private function verify(string $jwt, SsoConnection $connection, array $doc, string $nonce): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new SsoError('token_malformed');
        }
        $header = json_decode(self::b64urlDecode($parts[0]), true);
        $claims = json_decode(self::b64urlDecode($parts[1]), true);
        if (! is_array($header) || ! is_array($claims) || ($header['alg'] ?? null) !== 'RS256') {
            throw new SsoError('token_algorithm');
        }

        $pem = $this->signingKey($doc['jwks_uri'], $header['kid'] ?? null);
        if (openssl_verify($parts[0].'.'.$parts[1], self::b64urlDecode($parts[2]), $pem, OPENSSL_ALGO_SHA256) !== 1) {
            throw new SsoError('token_signature');
        }

        $audiences = (array) ($claims['aud'] ?? []);
        $now = time();
        $valid = ($claims['iss'] ?? null) === $doc['issuer']
            && in_array($connection->client_id, $audiences, true)
            && (count($audiences) === 1 || ($claims['azp'] ?? null) === $connection->client_id)
            && is_numeric($claims['exp'] ?? null) && $now <= $claims['exp'] + self::LEEWAY
            && (! isset($claims['iat']) || (is_numeric($claims['iat']) && $now >= $claims['iat'] - self::LEEWAY))
            && is_string($claims['nonce'] ?? null) && hash_equals($nonce, $claims['nonce']);
        if (! $valid) {
            throw new SsoError('token_claims');
        }

        return $claims;
    }

    private function signingKey(string $jwksUri, ?string $kid): string
    {
        foreach ([false, true] as $fresh) {
            $cacheKey = 'oidc:jwks:'.sha1($jwksUri);
            $keys = ! $fresh && is_array($cached = Cache::get($cacheKey)) ? $cached : null;
            if ($keys === null) {
                $keys = $this->getJson($jwksUri)['keys'] ?? [];
                Cache::put($cacheKey, $keys, 3600);
            }
            foreach ((array) $keys as $jwk) {
                if (($jwk['kty'] ?? null) === 'RSA' && ($kid === null || ($jwk['kid'] ?? null) === $kid) && ($jwk['use'] ?? 'sig') === 'sig'
                    && is_string($jwk['n'] ?? null) && is_string($jwk['e'] ?? null)) {
                    return self::rsaPem(self::b64urlDecode($jwk['n']), self::b64urlDecode($jwk['e']));
                }
            }
        }

        throw new SsoError('token_key_unknown');
    }

    private function getJson(string $url): array
    {
        try {
            $response = $this->http->to($url)->get($url);
        } catch (ConnectionException) {
            throw new SsoError('provider_unreachable');
        }
        $json = $response->successful() ? $response->json() : null;
        if (! is_array($json)) {
            throw new SsoError('provider_bad_response');
        }

        return $json;
    }

    /** DER-encodes an RSA public key (modulus, exponent) as a PEM SubjectPublicKeyInfo. */
    private static function rsaPem(string $n, string $e): string
    {
        $der = function (string $tag, string $body): string {
            $len = strlen($body);
            $bytes = ltrim(pack('N', $len), "\x00");

            return $tag.($len < 0x80 ? chr($len) : chr(0x80 | strlen($bytes)).$bytes).$body;
        };
        $int = fn (string $x) => $der("\x02", ord($x[0] ?? "\x00") > 0x7F ? "\x00".$x : $x);
        $algorithm = $der("\x30", $der("\x06", "\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01")."\x05\x00");
        $spki = $der("\x30", $algorithm.$der("\x03", "\x00".$der("\x30", $int($n).$int($e))));

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    public static function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function b64urlDecode(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }
}
