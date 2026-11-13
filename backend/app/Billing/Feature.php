<?php

namespace App\Billing;

/**
 * Plan-gated modules. Core HR (organization, people, departments, holidays, audit) is in every plan.
 * Each feature lists the API path prefixes (after /api/v1/) it covers, so new routes under a prefix are gated automatically.
 */
enum Feature: string
{
    case Attendance = 'attendance';
    case Leave = 'leave';
    case Payroll = 'payroll';
    case Recruitment = 'recruitment';
    case Onboarding = 'onboarding';
    case Helpdesk = 'helpdesk';
    case Documents = 'documents';
    case Performance = 'performance';

    /** @return list<string> path patterns, `*` matches one segment */
    public function paths(): array
    {
        return match ($this) {
            self::Attendance => ['attendance', 'shifts', 'work-schedules', 'employees/*/schedule-assignments'],
            self::Leave => ['leave', 'leave-types'],
            self::Payroll => ['payroll', 'reimbursements', 'employees/*/compensation', 'employees/*/compensation-history'],
            self::Recruitment => ['recruitment'],
            self::Onboarding => ['onboarding'],
            self::Helpdesk => ['helpdesk'],
            self::Documents => ['document-templates', 'document-requests'],
            self::Performance => ['performance'],
        };
    }

    /** The feature that gates an API path (relative to /api/v1/), if any. */
    public static function forPath(string $path): ?self
    {
        $segments = explode('/', trim($path, '/'));
        foreach (self::cases() as $feature) {
            foreach ($feature->paths() as $pattern) {
                $parts = explode('/', $pattern);
                if (count($segments) < count($parts)) {
                    continue;
                }
                $match = true;
                foreach ($parts as $i => $part) {
                    if ($part !== '*' && $part !== $segments[$i]) {
                        $match = false;
                        break;
                    }
                }
                if ($match) {
                    return $feature;
                }
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $f) => $f->value, self::cases());
    }
}
