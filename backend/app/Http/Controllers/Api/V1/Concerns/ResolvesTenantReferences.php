<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Resolves public ids from request bodies through the tenant-scoped model query, so an id that belongs
 * to another tenant is indistinguishable from one that does not exist.
 */
trait ResolvesTenantReferences
{
    /**
     * @template T of Model
     *
     * @param  class-string<T>  $class
     * @return T
     */
    protected function owned(string $class, string $publicId, string $field): Model
    {
        return $class::query()->where('public_id', $publicId)->first()
            ?? throw ValidationException::withMessages([$field => __('The selected :attribute is invalid.', ['attribute' => str_replace('_', ' ', $field)])]);
    }
}
