<?php

namespace App\Audit;

use Illuminate\Support\Facades\DB;

/**
 * Append-only HMAC hash chains, one per tenant and category ("t{tenant}:{category}" or "platform:{category}").
 * Each entry stores the previous entry's hash; editing, deleting or re-ordering entries breaks verification.
 * Retention removes only the oldest prefix of a chain and moves its anchor forward.
 */
final class AuditChain
{
    private const FIELDS = ['public_id', 'chain', 'sequence', 'tenant_id', 'actor_user_id', 'actor_type', 'category', 'event', 'outcome',
        'subject_type', 'subject_id', 'subject_public_id', 'old_values', 'new_values', 'metadata', 'ip_address', 'user_agent',
        'http_method', 'route', 'request_id', 'created_at', 'prev_hash'];

    private const INTS = ['sequence', 'tenant_id', 'actor_user_id', 'subject_id'];

    private const JSON = ['old_values', 'new_values', 'metadata'];

    public static function key(?int $tenantId, string $category): string
    {
        return ($tenantId ? "t{$tenantId}" : 'platform').':'.$category;
    }

    public static function append(array $row): void
    {
        $chain = self::key($row['tenant_id'], $row['category']);

        DB::transaction(function () use ($row, $chain) {
            DB::table('audit_chains')->insertOrIgnore(['chain' => $chain, 'tenant_id' => $row['tenant_id'], 'category' => $row['category'], 'updated_at' => now()]);
            $state = DB::table('audit_chains')->where('chain', $chain)->lockForUpdate()->first();

            $row['chain'] = $chain;
            $row['sequence'] = (int) $state->last_sequence + 1;
            $row['prev_hash'] = $state->last_hash;
            $row['hash'] = self::hash($row);

            DB::table('audit_logs')->insert($row);
            DB::table('audit_chains')->where('chain', $chain)->update(['last_sequence' => $row['sequence'], 'last_hash' => $row['hash'], 'updated_at' => now()]);
        });
    }

    public static function hash(array|object $row): string
    {
        $row = (array) $row;
        $canonical = [];
        foreach (self::FIELDS as $field) {
            $value = $row[$field] ?? null;
            $canonical[$field] = match (true) {
                $value === null => null,
                in_array($field, self::INTS, true) => (int) $value,
                in_array($field, self::JSON, true) => self::canonicalJson(is_string($value) ? json_decode($value, true) : $value),
                default => (string) $value,
            };
        }

        return hash_hmac('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), self::key_());
    }

    /** @return array{chain: string, entries: int, valid: bool, errors: list<string>} */
    public static function verify(string $chain): array
    {
        $state = DB::table('audit_chains')->where('chain', $chain)->first();
        $errors = [];
        $expected = (int) ($state?->anchor_sequence ?? 0) + 1;
        $prev = $state?->anchor_hash;
        $count = 0;

        foreach (DB::table('audit_logs')->where('chain', $chain)->orderBy('sequence')->lazyById(500) as $entry) {
            if ((int) $entry->sequence !== $expected) {
                $errors[] = "Missing entries before sequence {$entry->sequence} (expected {$expected}).";
            }
            if ($entry->prev_hash !== $prev) {
                $errors[] = "Broken link at sequence {$entry->sequence}.";
            }
            if (! hash_equals(self::hash($entry), (string) $entry->hash)) {
                $errors[] = "Entry {$entry->sequence} was modified.";
            }
            $prev = $entry->hash;
            $expected = (int) $entry->sequence + 1;
            $count++;
        }

        if (! $state || $expected - 1 !== (int) $state->last_sequence || $prev !== $state->last_hash) {
            $errors[] = 'The newest entries of the chain are missing or the chain head does not match.';
        }

        return ['chain' => $chain, 'entries' => $count, 'valid' => $errors === [], 'errors' => array_slice($errors, 0, 20)];
    }

    private static function canonicalJson(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($v) => self::canonicalJson($v), $value);
    }

    private static function key_(): string
    {
        return (string) (config('audit.hmac_key') ?: hash_hmac('sha256', 'wingora-audit-chain', (string) config('app.key')));
    }
}
