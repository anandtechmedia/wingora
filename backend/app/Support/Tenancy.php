<?php

namespace App\Support;

use App\Models\Tenant;

/**
 * Holds the tenant for the current request, job or command. It is only ever
 * set explicitly (middleware, jobs, provisioning) and never inferred.
 */
final class Tenancy
{
    private static ?Tenant $tenant = null;

    public static function set(?Tenant $tenant): void
    {
        self::$tenant = $tenant;
    }

    public static function current(): ?Tenant
    {
        return self::$tenant;
    }

    public static function id(): ?int
    {
        return self::$tenant?->id;
    }

    public static function forget(): void
    {
        self::$tenant = null;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function run(Tenant $tenant, callable $callback): mixed
    {
        $previous = self::$tenant;
        self::$tenant = $tenant;

        try {
            return $callback();
        } finally {
            self::$tenant = $previous;
        }
    }
}
