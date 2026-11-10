<?php

namespace App\Payroll\Providers;

use InvalidArgumentException;

final class ProviderRegistry
{
    /** @return array<string, PayrollProvider> */
    public function all(): array
    {
        $providers = [];
        foreach (config('payroll.providers', [GenericCsvProvider::class]) as $class) {
            $provider = app($class);
            $providers[$provider->key()] = $provider;
        }

        return $providers;
    }

    public function get(string $key): PayrollProvider
    {
        return $this->all()[$key] ?? throw new InvalidArgumentException("Unknown payroll provider {$key}.");
    }
}
