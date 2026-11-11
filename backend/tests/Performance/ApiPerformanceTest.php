<?php

namespace Tests\Performance;

use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Country\CountryTestCase;

/**
 * Opt-in benchmark: seeds one large tenant and records wall time, query count and query time per endpoint.
 * Run against MySQL: `php artisan test --group=performance` with DB_CONNECTION=mysql and PERF_OUT=<file.json>.
 */
#[Group('performance')]
class ApiPerformanceTest extends CountryTestCase
{
    private const EMPLOYEES = 5000;

    private const ITERATIONS = 15;

    public function test_measure_heavy_endpoints(): void
    {
        // Measure the endpoints, not the per-minute API limiter.
        RateLimiter::for('api', fn () => Limit::none());
        RateLimiter::for('exports', fn () => Limit::none());
        $tenant = $this->makeTenant();
        $hr = $this->hrAdmin($tenant);
        $first = $this->seedTenant($tenant, $hr);
        $from = now()->subDays(20)->toDateString();
        $to = now()->toDateString();

        $endpoints = [
            'dashboard' => '/api/v1/dashboard',
            'employees p25' => '/api/v1/employees?per_page=25',
            'employees p100' => '/api/v1/employees?per_page=100',
            'employees search' => '/api/v1/employees?search=Ander&per_page=25',
            'employees sort hire' => '/api/v1/employees?sort=-hire_date&per_page=25',
            'employee profile' => "/api/v1/employees/{$first->public_id}",
            'employee documents' => "/api/v1/employees/{$first->public_id}/documents",
            'attendance records' => "/api/v1/attendance/records?from={$from}&to={$to}&per_page=50",
            'attendance report' => "/api/v1/attendance/report?from={$from}&to={$to}",
            'leave requests p50' => '/api/v1/leave/requests?scope=all&per_page=50',
            'audit logs p50' => '/api/v1/audit-logs?per_page=50',
            'helpdesk tickets p50' => '/api/v1/helpdesk/tickets?per_page=50',
            'document requests p50' => '/api/v1/document-requests?scope=all&per_page=50',
            'users p100' => '/api/v1/users?per_page=100',
        ];

        if (env('PERF_EXPERIMENT')) {
            $this->experiment($tenant, $from, $to);

            return;
        }
        $results = [];
        foreach ($endpoints as $name => $uri) {
            $results[$name] = $this->measure($hr, $uri);
        }
        // N+1 check: query count must not grow with page size.
        foreach (['employees' => '/api/v1/employees', 'leave requests' => '/api/v1/leave/requests?scope=all', 'helpdesk tickets' => '/api/v1/helpdesk/tickets',
            'document requests' => '/api/v1/document-requests?scope=all', 'audit logs' => '/api/v1/audit-logs', 'attendance records' => "/api/v1/attendance/records?from={$from}&to={$to}"] as $name => $uri) {
            $sep = str_contains($uri, '?') ? '&' : '?';
            $results["n+1 {$name}"] = ['queries_p5' => $this->measure($hr, "{$uri}{$sep}per_page=5", 1)['queries'],
                'queries_p50' => $this->measure($hr, "{$uri}{$sep}per_page=50", 1)['queries']];
        }

        file_put_contents(env('PERF_OUT', storage_path('perf.json')), json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->assertNotEmpty($results);
    }

    private function experiment(Tenant $tenant, string $from, string $to): void
    {
        Tenancy::run($tenant, function () use ($from, $to) {
            $out = [];
            $visible = Employee::query()->select('employees.id');
            $variants = [
                'records in-subquery' => AttendanceRecord::query()->whereIn('employee_id', $visible)->where('work_date', '>=', $from)->where('work_date', '<=', $to)->orderByDesc('work_date')->orderBy('employee_id')->limit(50),
                'records exists' => AttendanceRecord::query()->whereExists(fn ($q) => $q->from('employees')->whereColumn('employees.id', 'attendance_records.employee_id')->whereNull('employees.deleted_at')->where('employees.tenant_id', Tenancy::current()->id))->where('work_date', '>=', $from)->where('work_date', '<=', $to)->orderByDesc('work_date')->orderBy('employee_id')->limit(50),
                'leave in-subquery' => LeaveRequest::query()->whereIn('employee_id', $visible)->orderByDesc('start_date')->orderByDesc('id')->limit(50),
                'audit' => AuditLog::query()->orderByDesc('id')->limit(51),
            ];
            foreach ($variants as $name => $q) {
                $out[$name] = ['sql' => $q->toRawSql(), 'analyze' => array_map(fn ($r) => (array) $r, DB::select('EXPLAIN ANALYZE '.$q->toRawSql()))];
            }
            file_put_contents(env('PERF_OUT'), json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        });
        $this->assertTrue(true);
    }

    private function measure(User $user, string $uri, int $iterations = self::ITERATIONS): array
    {
        $this->apiAs($user, 'GET', $uri); // warm-up
        $wall = $queryMs = [];
        $queries = 0;
        $slowest = [];
        $status = 0;
        $bytes = 0;
        for ($i = 0; $i < $iterations; $i++) {
            $log = [];
            DB::listen(function ($q) use (&$log) {
                $log[] = [$q->time, $q->sql, $q->bindings];
            });
            $start = hrtime(true);
            $response = $this->apiAs($user, 'GET', $uri);
            $wall[] = (hrtime(true) - $start) / 1e6;
            $this->app['events']->forget(QueryExecuted::class);
            $status = $response->status();
            $bytes = strlen((string) $response->getContent());
            $queries = count($log);
            $queryMs[] = array_sum(array_column($log, 0));
            usort($log, fn ($a, $b) => $b[0] <=> $a[0]);
            $slowest = array_map(fn ($q) => round($q[0], 2).'ms '.Str::limit($q[1], 160), array_slice($log, 0, 2));
            $top = $log[0] ?? null;
        }
        sort($wall);
        sort($queryMs);
        $plan = $top && str_starts_with($top[1], 'select') && DB::getDriverName() === 'mysql'
            ? array_map(fn ($r) => "{$r->table}: type={$r->type} key={$r->key} rows={$r->rows} extra={$r->Extra}", DB::select('EXPLAIN '.$top[1], $top[2])) : [];
        $error = $status >= 500 ? Str::limit((string) $response->getContent(), 400) : null;

        return ['status' => $status, 'median_ms' => round($wall[intdiv(count($wall), 2)], 1), 'p90_ms' => round($wall[(int) floor(count($wall) * 0.9)] ?? end($wall), 1),
            'queries' => $queries, 'query_ms' => round($queryMs[intdiv(count($queryMs), 2)], 1), 'bytes' => $bytes, 'slowest' => $slowest, 'plan' => $plan, 'error' => $error];
    }

    private function seedTenant(Tenant $tenant, User $hr): Employee
    {
        return Tenancy::run($tenant, function () use ($tenant, $hr) {
            $entity = $this->entity($tenant, 'US');
            $now = now();
            $t = $tenant->id;
            $departments = [];
            for ($d = 0; $d < 25; $d++) {
                $departments[] = DB::table('departments')->insertGetId(['public_id' => (string) Str::ulid(), 'tenant_id' => $t, 'name' => "Dept {$d}", 'created_at' => $now, 'updated_at' => $now]);
            }
            $names = ['Anderson', 'Baker', 'Clark', 'Diaz', 'Evans', 'Garcia', 'Hughes', 'Ito', 'Jones', 'Khan', 'Lopez', 'Miller', 'Nguyen', 'Ortiz', 'Patel', 'Reyes', 'Smith', 'Turner', 'Usman', 'Wong'];
            $rows = [];
            for ($i = 0; $i < self::EMPLOYEES; $i++) {
                $hire = $now->copy()->subDays(($i * 7) % 3650);
                $rows[] = ['public_id' => (string) Str::ulid(), 'tenant_id' => $t, 'employee_number' => sprintf('E%05d', $i), 'first_name' => 'Emp'.$i, 'last_name' => $names[$i % 20],
                    'work_email' => "e{$i}@example.test", 'job_title' => 'Analyst', 'status' => $i % 15 === 0 ? 'terminated' : 'active', 'department_id' => $departments[$i % 25],
                    'legal_entity_id' => $entity->id, 'timezone' => 'America/New_York', 'hire_date' => $hire->toDateString(), 'hire_md' => $hire->format('m-d'),
                    'birthday_md' => $now->copy()->addDays($i % 365)->format('m-d'), 'created_at' => $now, 'updated_at' => $now];
                if (count($rows) === 1000) {
                    DB::table('employees')->insert($rows);
                    $rows = [];
                }
            }
            $ids = DB::table('employees')->where('tenant_id', $t)->orderBy('id')->pluck('id')->all();
            DB::table('employees')->where('tenant_id', $t)->where('id', '>', $ids[0])->update(['manager_id' => $ids[0]]);

            $rows = [];
            foreach (array_slice($ids, 0, 1000) as $e) {
                for ($d = 0; $d < 20; $d++) {
                    $day = $now->copy()->subDays($d);
                    $rows[] = ['public_id' => (string) Str::ulid(), 'tenant_id' => $t, 'employee_id' => $e, 'work_date' => $day->toDateString(), 'timezone' => 'America/New_York',
                        'clock_in_at' => $day->copy()->setTime(13, 0), 'clock_out_at' => $day->copy()->setTime(21, 0), 'worked_minutes' => 480, 'created_at' => $now, 'updated_at' => $now];
                }
                if (count($rows) >= 2000) {
                    DB::table('attendance_records')->insert($rows);
                    $rows = [];
                }
            }
            DB::table('attendance_records')->insert($rows);

            $leaveType = DB::table('leave_types')->insertGetId(['public_id' => (string) Str::ulid(), 'tenant_id' => $t, 'name' => 'Vacation', 'code' => 'VAC', 'created_at' => $now, 'updated_at' => $now]);
            $rows = [];
            foreach (array_slice($ids, 0, 2000) as $n => $e) {
                $rows[] = ['public_id' => (string) Str::ulid(), 'tenant_id' => $t, 'employee_id' => $e, 'leave_type_id' => $leaveType, 'start_date' => $now->copy()->addDays($n % 60)->toDateString(),
                    'end_date' => $now->copy()->addDays($n % 60 + 1)->toDateString(), 'days' => 2, 'status' => 'pending', 'requested_by' => $hr->id, 'created_at' => $now, 'updated_at' => $now];
            }
            foreach (array_chunk($rows, 1000) as $chunk) {
                DB::table('leave_requests')->insert($chunk);
            }

            $category = DB::table('helpdesk_categories')->insertGetId(['public_id' => (string) Str::ulid(), 'tenant_id' => $t, 'name' => 'Payroll', 'created_at' => $now, 'updated_at' => $now]);
            $rows = [];
            $description = Crypt::encryptString('My question about the last payslip.');
            for ($n = 1; $n <= 2000; $n++) {
                $rows[] = ['public_id' => (string) Str::ulid(), 'tenant_id' => $t, 'number' => $n, 'helpdesk_category_id' => $category, 'requester_id' => $hr->id, 'employee_id' => $ids[$n % 5000],
                    'subject' => "Ticket {$n}", 'description' => $description, 'priority' => 'normal', 'status' => 'open', 'version' => 1, 'created_at' => $now, 'updated_at' => $now];
            }
            foreach (array_chunk($rows, 1000) as $chunk) {
                DB::table('helpdesk_tickets')->insert($chunk);
            }

            $template = DB::table('document_templates')->insertGetId(['public_id' => (string) Str::ulid(), 'tenant_id' => $t, 'name' => 'Employment letter', 'delivery' => 'generated', 'fields' => '[]', 'created_at' => $now, 'updated_at' => $now]);
            $answers = Crypt::encryptString('[]');
            $rows = [];
            for ($n = 1; $n <= 1000; $n++) {
                $rows[] = ['public_id' => (string) Str::ulid(), 'tenant_id' => $t, 'number' => $n, 'document_template_id' => $template, 'employee_id' => $ids[$n], 'requester_id' => $hr->id,
                    'answers' => $answers, 'status' => 'pending_hr', 'version' => 1, 'created_at' => $now, 'updated_at' => $now];
            }
            DB::table('document_requests')->insert($rows);

            $rows = [];
            for ($n = 0; $n < 150; $n++) {
                $rows[] = ['public_id' => (string) Str::ulid(), 'tenant_id' => $t, 'uploaded_by' => $hr->id, 'employee_id' => $ids[0], 'category' => $n % 3 === 0 ? 'contract' : 'general', 'disk' => 'local',
                    'path' => "perf/{$n}.pdf", 'original_name' => "doc-{$n}.pdf", 'mime_type' => 'application/pdf', 'size' => 1024, 'sha256' => str_repeat('a', 64), 'created_at' => $now, 'updated_at' => $now];
            }
            DB::table('stored_files')->insert($rows);

            $rows = [];
            for ($n = 0; $n < 20000; $n++) {
                $rows[] = ['public_id' => (string) Str::ulid(), 'chain' => 'perf', 'sequence' => $n + 1, 'tenant_id' => $t, 'actor_user_id' => $hr->id, 'actor_type' => 'user', 'category' => 'employee_profile',
                    'event' => 'employee.updated', 'outcome' => 'success', 'subject_type' => 'employee', 'subject_id' => $ids[$n % 5000], 'created_at' => $now->copy()->subMinutes($n), 'prev_hash' => str_repeat('0', 64), 'hash' => str_repeat('f', 64)];
                if (count($rows) === 2000) {
                    DB::table('audit_logs')->insert($rows);
                    $rows = [];
                }
            }

            return Employee::query()->whereKey($ids[0])->firstOrFail();
        });
    }
}
