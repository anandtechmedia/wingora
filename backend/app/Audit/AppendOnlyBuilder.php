<?php

namespace App\Audit;

use Illuminate\Database\Eloquent\Builder;
use LogicException;

/** Blocks bulk updates and deletes through Eloquent; retention pruning is the only deletion path. */
class AppendOnlyBuilder extends Builder
{
    public function update(array $values)
    {
        throw new LogicException('Audit records are append-only.');
    }

    public function delete()
    {
        throw new LogicException('Audit records are append-only.');
    }

    public function forceDelete()
    {
        throw new LogicException('Audit records are append-only.');
    }

    public function increment($column, $amount = 1, array $extra = [])
    {
        throw new LogicException('Audit records are append-only.');
    }

    public function decrement($column, $amount = 1, array $extra = [])
    {
        throw new LogicException('Audit records are append-only.');
    }
}
