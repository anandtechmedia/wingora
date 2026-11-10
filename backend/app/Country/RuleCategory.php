<?php

namespace App\Country;

use Illuminate\Validation\Rule;

/**
 * Configurable rule categories. Payloads are validated per category and anything not listed is dropped,
 * so rule content stays structured data rather than code. Payroll entries are placeholders: no statutory
 * calculations are implemented.
 */
enum RuleCategory: string
{
    case EmploymentTypes = 'employment_types';
    case Leave = 'leave';
    case Payroll = 'payroll';
    case Compliance = 'compliance';
    case Holidays = 'holidays';

    private const KEY = ['required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/', 'distinct'];

    /** @return array<string, mixed> Validation rules for `payload.*`. */
    public function payloadRules(): array
    {
        $rules = match ($this) {
            self::EmploymentTypes => [
                'types' => ['required', 'array', 'min:1', 'max:50'],
                'types.*.key' => self::KEY,
                'types.*.name' => ['required', 'string', 'max:120'],
                'types.*.description' => ['nullable', 'string', 'max:500'],
            ],
            self::Leave => [
                'leave_types' => ['required', 'array', 'min:1', 'max:50'],
                'leave_types.*.key' => self::KEY,
                'leave_types.*.name' => ['required', 'string', 'max:120'],
                'leave_types.*.paid' => ['required', 'boolean'],
                'leave_types.*.unit' => ['required', Rule::in(['days', 'hours'])],
                'leave_types.*.entitlement' => ['present', 'nullable', 'numeric', 'between:0,366'],
                'leave_types.*.notes' => ['nullable', 'string', 'max:1000'],
            ],
            self::Payroll => [
                'pay_frequencies' => ['required', 'array', 'min:1'],
                'pay_frequencies.*' => ['required', Rule::in(['weekly', 'biweekly', 'semimonthly', 'monthly']), 'distinct'],
                'statutory_items' => ['present', 'array', 'max:50'],
                'statutory_items.*.key' => self::KEY,
                'statutory_items.*.name' => ['required', 'string', 'max:120'],
                'statutory_items.*.notes' => ['nullable', 'string', 'max:1000'],
            ],
            self::Compliance => [
                'requirements' => ['required', 'array', 'min:1', 'max:100'],
                'requirements.*.key' => self::KEY,
                'requirements.*.name' => ['required', 'string', 'max:160'],
                'requirements.*.description' => ['nullable', 'string', 'max:1000'],
                'requirements.*.reference' => ['nullable', 'string', 'max:500'],
            ],
            self::Holidays => [
                'holidays' => ['required', 'array', 'min:1', 'max:100'],
                'holidays.*.date' => ['required', 'date_format:Y-m-d', 'distinct'],
                'holidays.*.name' => ['required', 'string', 'max:160'],
                'holidays.*.type' => ['required', Rule::in(['public', 'optional', 'observance'])],
                'holidays.*.paid' => ['required', 'boolean'],
            ],
        };

        return ['payload' => ['required', 'array']] + collect($rules)->mapWithKeys(fn ($r, $k) => ['payload.'.$k => $r])->all();
    }

    /** Keys of the entries in a payload, used e.g. to validate an employee's employment type. */
    public function entryKeys(array $payload): array
    {
        return match ($this) {
            self::EmploymentTypes => array_column($payload['types'] ?? [], 'key'),
            self::Leave => array_column($payload['leave_types'] ?? [], 'key'),
            self::Payroll => array_column($payload['statutory_items'] ?? [], 'key'),
            self::Compliance => array_column($payload['requirements'] ?? [], 'key'),
            self::Holidays => array_column($payload['holidays'] ?? [], 'date'),
        };
    }
}
