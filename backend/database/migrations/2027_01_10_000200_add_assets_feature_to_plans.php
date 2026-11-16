<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Grants the new assets feature to the Growth and Enterprise plans. Additive; existing features are kept. */
return new class extends Migration
{
    private const PLANS = ['growth', 'enterprise'];

    public function up(): void
    {
        $this->each(fn (array $features) => in_array('assets', $features, true) ? $features : [...$features, 'assets']);
    }

    public function down(): void
    {
        $this->each(fn (array $features) => array_values(array_diff($features, ['assets'])));
    }

    private function each(callable $change): void
    {
        foreach (DB::table('plans')->whereIn('code', self::PLANS)->get(['id', 'features']) as $plan) {
            DB::table('plans')->where('id', $plan->id)->update(['features' => json_encode($change(json_decode($plan->features, true) ?: []))]);
        }
    }
};
