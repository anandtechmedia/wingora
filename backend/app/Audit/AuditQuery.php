<?php

namespace App\Audit;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Validated, parameter-bound search filters shared by tenant and platform audit reports. */
final class AuditQuery
{
    public static function rules(): array
    {
        return [
            'category' => ['sometimes', Rule::enum(AuditCategory::class)],
            'event' => ['sometimes', 'string', 'max:81', 'regex:/^[a-z0-9_.]+\*?$/'],
            'outcome' => ['sometimes', Rule::in(['success', 'failure'])],
            'actor_id' => ['sometimes', 'string', 'size:26'],
            'subject_type' => ['sometimes', 'string', 'max:60', 'regex:/^[a-z_]+$/'],
            'subject_id' => ['sometimes', 'string', 'size:26'],
            'request_id' => ['sometimes', 'string', 'max:64'],
            'ip' => ['sometimes', 'ip'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    public static function apply(Builder $query, array $f): Builder
    {
        return $query
            ->when($f['category'] ?? null, fn ($q, $v) => $q->where('category', $v))
            ->when($f['event'] ?? null, fn ($q, $v) => str_ends_with($v, '*')
                ? $q->where('event', 'like', addcslashes(rtrim($v, '*'), '%_\\').'%')
                : $q->where('event', $v))
            ->when($f['outcome'] ?? null, fn ($q, $v) => $q->where('outcome', $v))
            ->when($f['actor_id'] ?? null, fn ($q, $v) => $q->whereIn('actor_user_id', fn ($s) => $s->select('id')->from('users')->where('public_id', $v)))
            ->when($f['subject_type'] ?? null, fn ($q, $v) => $q->where('subject_type', 'App\\Models\\'.Str::studly($v)))
            ->when($f['subject_id'] ?? null, fn ($q, $v) => $q->where('subject_public_id', $v))
            ->when($f['request_id'] ?? null, fn ($q, $v) => $q->where('request_id', $v))
            ->when($f['ip'] ?? null, fn ($q, $v) => $q->where('ip_address', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v.' 00:00:00'))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', $v.' 23:59:59'));
    }
}
