<?php

namespace App\Payroll\Providers;

use App\Models\PayrollRun;

/**
 * Boundary to an external payroll provider (the system that calculates tax and files statutory returns).
 * Wingora sends the prepared register out and brings provider-supplied lines back; it never computes them itself.
 */
interface PayrollProvider
{
    public function key(): string;

    public function name(): string;

    /** @return array{filename: string, mime: string, content: string} */
    public function export(PayrollRun $run): array;

    /**
     * Parses a provider result file into lines.
     *
     * @return list<array{employee_number: string, code: string, name: string, kind: string, amount: string, reference: ?string, row: int}>
     */
    public function parseResults(string $content): array;
}
