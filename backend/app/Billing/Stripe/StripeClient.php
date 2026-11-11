<?php

namespace App\Billing\Stripe;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Minimal Stripe REST client over Laravel's HTTP client (no SDK dependency). Only hosted Checkout, the hosted
 * Customer Portal and subscription updates are used, so card data never passes through Wingora.
 */
class StripeClient
{
    public function configured(): bool
    {
        return (string) config('billing.stripe.secret') !== '';
    }

    /** @return array<string, mixed> */
    public function get(string $path, array $query = []): array
    {
        return $this->send('get', $path, $query);
    }

    /** @return array<string, mixed> */
    public function post(string $path, array $params = []): array
    {
        return $this->send('post', $path, $params);
    }

    private function send(string $method, string $path, array $params): array
    {
        if (! $this->configured()) {
            throw new StripeError(__('Online payments are not configured.'), 503);
        }
        $request = Http::withToken((string) config('billing.stripe.secret'))
            ->timeout((int) config('billing.stripe.timeout'))
            ->acceptJson();
        if ($method === 'post') {
            $request = $request->asForm()->withHeaders(['Idempotency-Key' => (string) Str::uuid()]);
        }
        $url = rtrim((string) config('billing.stripe.api_base'), '/').'/'.ltrim($path, '/');
        try {
            $response = $method === 'post' ? $request->post($url, $params) : $request->get($url, $params);
        } catch (ConnectionException) {
            throw new StripeError(__('The payment provider could not be reached. Try again shortly.'), 503);
        }
        if ($response->failed()) {
            // Stripe error bodies carry no secrets; keep only the type and code.
            Log::warning('stripe.request_failed', ['path' => $path, 'status' => $response->status(),
                'type' => $response->json('error.type'), 'code' => $response->json('error.code')]);
            throw new StripeError(__('The payment provider rejected the request.'), 502);
        }

        return (array) $response->json();
    }
}
