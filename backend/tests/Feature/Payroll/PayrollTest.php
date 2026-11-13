<?php

namespace Tests\Feature\Payroll;

use App\Country\RuleCategory;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeCompensation;
use App\Models\LegalEntity;
use App\Models\Loan;
use App\Models\Reimbursement;
use App\Models\Tenant;
use App\Models\User;
use App\Payroll\PeriodGenerator;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Tests\Feature\Country\CountryTestCase;

class PayrollTest extends CountryTestCase
{
    private Tenant $tenant;

    private LegalEntity $us;

    private User $preparer;

    private User $approver;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant();
        $this->us = $this->entity($this->tenant, 'US');
        $this->approvedRule('US', null, RuleCategory::Payroll, ['pay_frequencies' => ['monthly', 'biweekly'],
            'statutory_items' => [['key' => 'us_fica', 'name' => 'FICA', 'notes' => null]]], '2026-01-01');
        $this->preparer = $this->makeUser($this->tenant, 'payroll-administrator');
        $this->approver = $this->makeUser($this->tenant, 'payroll-administrator');
        $this->owner = $this->makeUser($this->tenant, 'organization-owner');
    }

    /** @return array{0: User, 1: Employee} */
    private function staff(string $role = 'employee', ?Employee $manager = null, ?string $hire = '2020-01-01', ?Tenant $tenant = null, ?LegalEntity $entity = null): array
    {
        $tenant ??= $this->tenant;
        $entity ??= $this->us;
        $user = $this->makeUser($tenant, $role);
        $employee = $this->makeEmployee($tenant);
        $employee->forceFill(['user_id' => $user->id, 'legal_entity_id' => $entity->id, 'manager_id' => $manager?->id, 'hire_date' => $hire, 'status' => 'active'])->saveQuietly();

        return [$user->fresh(), $employee->fresh()];
    }

    private function calendar(int $levels = 2, string $frequency = 'monthly'): array
    {
        $calendar = $this->apiAs($this->owner, 'POST', '/api/v1/payroll/calendars', ['legal_entity' => $this->us->public_id, 'name' => 'US monthly',
            'pay_frequency' => $frequency, 'first_period_start' => '2027-01-01', 'approval_levels' => $levels, 'pay_date_offset_days' => 3])->assertCreated()->json('data.id');
        $periods = $this->apiAs($this->owner, 'POST', "/api/v1/payroll/calendars/{$calendar}/periods", ['count' => 3])->assertCreated()->json('data');

        return [$calendar, array_column($periods, 'id')];
    }

    private function structure(): string
    {
        $id = $this->apiAs($this->owner, 'POST', '/api/v1/payroll/structures', ['legal_entity' => $this->us->public_id, 'name' => 'Staff', 'code' => 'STAFF'])->assertCreated()->json('data.id');
        $this->apiAs($this->owner, 'POST', "/api/v1/payroll/structures/{$id}/components", ['code' => 'HOUSING', 'name' => 'Housing allowance', 'kind' => 'earning', 'method' => 'fixed', 'amount' => '500.00', 'effective_from' => '2026-01-01'])->assertCreated();
        $this->apiAs($this->owner, 'POST', "/api/v1/payroll/structures/{$id}/components", ['code' => 'PENSION', 'name' => 'Pension plan', 'kind' => 'deduction', 'method' => 'percent_of_base', 'rate_percent' => 5, 'effective_from' => '2026-01-01'])->assertCreated();
        $this->apiAs($this->owner, 'POST', "/api/v1/payroll/structures/{$id}/components", ['code' => 'FICA', 'name' => 'FICA', 'kind' => 'deduction', 'method' => 'manual', 'rule_key' => 'us_fica', 'effective_from' => '2026-01-01'])->assertCreated();

        return $id;
    }

    private function pay(Employee $employee, string $amount = '60000.00', string $from = '2026-01-01', ?string $structure = null): void
    {
        $this->apiAs($this->preparer, 'POST', "/api/v1/employees/{$employee->public_id}/compensation-history", ['amount' => $amount, 'basis' => 'annual',
            'pay_frequency' => 'monthly', 'effective_from' => $from, 'salary_structure' => $structure, 'reason' => 'Offer'])->assertCreated();
    }

    private function startRun(string $period, string $type = 'regular'): string
    {
        $id = $this->apiAs($this->preparer, 'POST', '/api/v1/payroll/runs', ['period' => $period, 'type' => $type])->assertCreated()->json('data.id');
        $this->apiAs($this->preparer, 'POST', "/api/v1/payroll/runs/{$id}/prepare")->assertOk();

        return $id;
    }

    private function slips(string $run): array
    {
        return $this->apiAs($this->preparer, 'GET', "/api/v1/payroll/runs/{$run}/payslips")->assertOk()->json('data');
    }

    private function audit(): Builder
    {
        return AuditLog::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant->id);
    }

    private function line(array $slip, string $code): ?array
    {
        return collect($slip['lines'])->firstWhere('code', $code);
    }

    public function test_preparation_review_multi_level_approval_and_payslip_access(): void
    {
        [$employeeUser, $employee] = $this->staff();
        [$otherUser] = $this->staff();
        [, $periods] = $this->calendar(2);
        $this->pay($employee, structure: $this->structure());

        $run = $this->startRun($periods[0]);
        $slip = $this->slips($run)[0];
        $this->assertSame('5000.00', $this->line($slip, 'BASE')['amount']['amount']);
        $this->assertSame('calculated', $this->line($slip, 'BASE')['source']);
        $this->assertSame('500.00', $this->line($slip, 'HOUSING')['amount']['amount']);
        $this->assertSame('250.00', $this->line($slip, 'PENSION')['amount']['amount']);
        $this->assertNull($this->line($slip, 'FICA'), 'Statutory items are never calculated.');
        $this->assertSame('awaiting_input', $slip['issues'][0]['code']);

        // The statutory figure is entered by payroll staff, with a reason, and labelled as entered.
        $this->apiAs($this->preparer, 'POST', "/api/v1/payroll/runs/{$run}/payslips/{$slip['id']}/lines", ['code' => 'FICA', 'name' => 'FICA', 'kind' => 'deduction', 'amount' => '382.50'])->assertUnprocessable();
        $this->apiAs($this->preparer, 'POST', "/api/v1/payroll/runs/{$run}/payslips/{$slip['id']}/lines", ['code' => 'FICA', 'name' => 'FICA', 'kind' => 'deduction', 'amount' => '382.50', 'reason' => 'From provider worksheet'])
            ->assertCreated()->assertJsonPath('data.source', 'manual');
        // Recalculation keeps entered lines and clears the issue.
        $this->apiAs($this->preparer, 'POST', "/api/v1/payroll/runs/{$run}/prepare")->assertOk()
            ->assertJsonPath('data.gross.amount', '5500.00')->assertJsonPath('data.deductions.amount', '632.50')->assertJsonPath('data.net.amount', '4867.50');
        $this->assertSame([], $this->slips($run)[0]['issues']);

        $this->apiAs($this->preparer, 'POST', "/api/v1/payroll/runs/{$run}/submit")->assertOk()->assertJsonPath('data.status', 'in_review');
        $this->apiAs($this->preparer, 'POST', "/api/v1/payroll/runs/{$run}/approve")->assertForbidden();
        $this->apiAs($employeeUser, 'POST', "/api/v1/payroll/runs/{$run}/approve")->assertForbidden();
        $this->apiAs($this->approver, 'POST', "/api/v1/payroll/runs/{$run}/approve")->assertOk()->assertJsonPath('data.current_level', 2)->assertJsonPath('data.status', 'in_review');
        $this->apiAs($this->approver, 'POST', "/api/v1/payroll/runs/{$run}/approve")->assertForbidden();
        $this->apiAs($this->owner, 'POST', "/api/v1/payroll/runs/{$run}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->apiAs($this->owner, 'POST', "/api/v1/payroll/runs/{$run}/approve")->assertConflict();

        // Approved runs are immutable.
        $this->apiAs($this->preparer, 'POST', "/api/v1/payroll/runs/{$run}/prepare")->assertConflict();
        $this->apiAs($this->preparer, 'POST', "/api/v1/payroll/runs/{$run}/payslips/{$slip['id']}/lines", ['code' => 'BONUS', 'name' => 'Bonus', 'kind' => 'earning', 'amount' => '1.00', 'reason' => 'Late'])->assertConflict();

        $this->apiAs($employeeUser, 'GET', "/api/v1/payroll/payslips/{$slip['id']}/pdf")->assertForbidden();
        $this->apiAs($employeeUser, 'GET', '/api/v1/payroll/payslips/mine')->assertOk()->assertJsonCount(0, 'data');
        $this->apiAs($this->preparer, 'POST', "/api/v1/payroll/runs/{$run}/publish")->assertOk()->assertJsonPath('data.status', 'published');

        $this->apiAs($employeeUser, 'GET', '/api/v1/payroll/payslips/mine')->assertOk()->assertJsonPath('data.0.net.amount', '4867.50');
        $pdf = $this->apiAs($employeeUser, 'GET', "/api/v1/payroll/payslips/{$slip['id']}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-1.4', $pdf->getContent());
        $this->assertStringContainsString('Entered', $pdf->getContent());
        $this->assertStringContainsString('Not a statutory payroll calculation', $pdf->getContent());
        $this->apiAs($otherUser, 'GET', "/api/v1/payroll/payslips/{$slip['id']}/pdf")->assertForbidden();
        $this->apiAs($otherUser, 'GET', "/api/v1/payroll/payslips/{$slip['id']}")->assertForbidden();

        $events = $this->audit()->pluck('event')->all();
        foreach (['payroll.run_created', 'payroll.run_prepared', 'payroll.line_entered', 'payroll.run_submitted', 'payroll.run_approved', 'payroll.run_published', 'payroll.payslip_viewed', 'payroll.compensation_changed'] as $event) {
            $this->assertContains($event, $events);
        }
        $this->assertSame(2, $this->audit()->where('event', 'payroll.run_approved')->count());
        $this->assertStringNotContainsString('6000000', json_encode($this->audit()->where('event', 'payroll.compensation_changed')->first()?->new_values ?? []));
    }

    public function test_rejection_returns_to_draft_and_starts_a_new_review_cycle(): void
    {
        [, $employee] = $this->staff();
        [, $periods] = $this->calendar(1);
        $this->pay($employee);
        $run = $this->startRun($periods[0]);
        $this->apiAs($this->preparer, 'POST', "/api/v1/payroll/runs/{$run}/submit")->assertOk();
        $this->apiAs($this->approver, 'POST', "/api/v1/payroll/runs/{$run}/reject", ['note' => 'no'])->assertUnprocessable();
        $this->apiAs($this->approver, 'POST', "/api/v1/payroll/runs/{$run}/reject", ['note' => 'Check the hours'])->assertOk()->assertJsonPath('data.status', 'draft');
        $this->apiAs($this->preparer, 'POST', "/api/v1/payroll/runs/{$run}/submit")->assertOk()->assertJsonPath('data.review_cycle', 2);
        // The earlier rejecter may decide again in the new cycle.
        $this->apiAs($this->approver, 'POST', "/api/v1/payroll/runs/{$run}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertSame(1, $this->audit()->where('event', 'payroll.run_rejected')->count());
    }

    public function test_proration_for_joiners_leavers_and_mid_period_changes(): void
    {
        [, $joiner] = $this->staff(hire: '2027-01-16');
        [, $leaver] = $this->staff();
        $leaver->forceFill(['termination_date' => '2027-01-10'])->saveQuietly();
        [, $raised] = $this->staff();
        [, $periods] = $this->calendar(1);
        $this->pay($joiner);
        $this->pay($leaver);
        $this->pay($raised);
        $this->pay($raised, '72000.00', '2027-01-11');

        $slips = collect($this->slips($this->startRun($periods[0])))->keyBy(fn ($s) => $s['employee']['employee_number']);
        // 5000.00 × 16/31 = 2580.645 → 2580.65 (half away from zero)
        $this->assertSame('2580.65', $slips[$joiner->employee_number]['gross']['amount']);
        $this->assertSame(16, $slips[$joiner->employee_number]['days_paid']);
        // 5000.00 × 10/31 = 1612.903 → 1612.90
        $this->assertSame('1612.90', $slips[$leaver->employee_number]['gross']['amount']);
        // 1612.90 + 6000.00 × 21/31 (4064.516 → 4064.52)
        $base = collect($slips[$raised->employee_number]['lines'])->where('code', 'BASE')->pluck('amount.amount')->all();
        $this->assertSame(['1612.90', '4064.52'], $base);
        $this->assertSame('5677.42', $slips[$raised->employee_number]['gross']['amount']);
    }

    public function test_loans_are_repaid_by_installments_once_per_approved_run(): void
    {
        [$employeeUser, $employee] = $this->staff();
        [, $periods] = $this->calendar(1);
        $this->pay($employee);
        $this->apiAs($this->preparer, 'POST', '/api/v1/payroll/loans', ['employee' => $employee->public_id, 'principal' => '500.00', 'installment' => '600.00', 'first_deduction_on' => '2027-01-01', 'reason' => 'Advance'])->assertUnprocessable();
        $this->apiAs($this->preparer, 'POST', '/api/v1/payroll/loans', ['employee' => $employee->public_id, 'principal' => '500.00', 'installment' => '400.00', 'first_deduction_on' => '2027-01-01', 'reason' => 'Advance'])->assertCreated();

        foreach ([[$periods[0], '400.00', '100.00'], [$periods[1], '100.00', '0.00']] as [$period, $deducted, $left]) {
            $run = $this->startRun($period);
            $loanLine = $this->line($this->slips($run)[0], 'LOAN');
            $this->assertSame($deducted, $loanLine['amount']['amount']);
            $this->assertSame($left, $loanLine['basis']['outstanding_after']);
            // Preparing again before approval does not double-count.
            $this->apiAs($this->preparer, 'POST', "/api/v1/payroll/runs/{$run}/prepare")->assertOk();
            $this->assertCount(1, collect($this->slips($run)[0]['lines'])->where('code', 'LOAN'));
            $this->apiAs($this->preparer, 'POST', "/api/v1/payroll/runs/{$run}/submit")->assertOk();
            $this->apiAs($this->approver, 'POST', "/api/v1/payroll/runs/{$run}/approve")->assertOk();
        }
        $loan = Tenancy::run($this->tenant, fn () => Loan::query()->first());
        $this->assertSame('closed', $loan->status);
        $this->assertNull($this->line($this->slips($this->startRun($periods[2]))[0], 'LOAN'));
        $this->apiAs($employeeUser, 'GET', '/api/v1/payroll/loans')->assertOk()->assertJsonPath('data.0.outstanding.amount', '0.00');
    }

    public function test_a_loan_installment_is_not_settled_twice_by_runs_prepared_together(): void
    {
        [, $employee] = $this->staff();
        [, $periods] = $this->calendar(1);
        $this->pay($employee);
        $this->apiAs($this->preparer, 'POST', '/api/v1/payroll/loans', ['employee' => $employee->public_id, 'principal' => '500.00', 'installment' => '400.00', 'first_deduction_on' => '2027-01-01', 'reason' => 'Advance'])->assertCreated();

        $first = $this->startRun($periods[0]);
        $second = $this->startRun($periods[1]);
        foreach ([$first, $second] as $run) {
            $this->apiAs($this->preparer, 'POST', "/api/v1/payroll/runs/{$run}/submit")->assertOk();
        }
        $this->apiAs($this->approver, 'POST', "/api/v1/payroll/runs/{$first}/approve")->assertOk();
        $this->apiAs($this->approver, 'POST', "/api/v1/payroll/runs/{$second}/approve")->assertConflict();

        $loan = Tenancy::run($this->tenant, fn () => Loan::query()->first());
        $this->assertSame(100_00, Tenancy::run($this->tenant, fn () => $loan->outstanding()));
    }

    public function test_monthly_periods_keep_their_month_end_anchor(): void
    {
        $this->assertSame(['2027-01-31', '2027-02-27'], PeriodGenerator::bounds('monthly', '2027-01-31', 31));
        $this->assertSame(['2027-02-28', '2027-03-30'], PeriodGenerator::bounds('monthly', '2027-02-28', 31));
        $this->assertSame(['2027-03-31', '2027-04-29'], PeriodGenerator::bounds('monthly', '2027-03-31', 31));
        $this->assertSame(['2027-01-01', '2027-01-31'], PeriodGenerator::bounds('monthly', '2027-01-01'));
        $this->assertSame(['2027-01-15', '2027-02-14'], PeriodGenerator::bounds('monthly', '2027-01-15', 15));
    }

    public function test_reimbursements_are_approved_by_manager_and_paid_once(): void
    {
        [$managerUser, $manager] = $this->staff('department-manager');
        [$employeeUser, $employee] = $this->staff(manager: $manager);
        [$peerUser] = $this->staff();
        [, $periods] = $this->calendar(1);
        $this->pay($employee);

        $receipt = $this->apiAs($employeeUser, 'POST', '/api/v1/files', ['file' => UploadedFile::fake()->createWithContent('r.pdf', "%PDF-1.4\n%%EOF"), 'category' => 'general'])->json('data.id');
        $id = $this->apiAs($employeeUser, 'POST', '/api/v1/reimbursements', ['amount' => '120.555', 'category' => 'travel', 'description' => 'Taxi', 'expense_date' => now()->subDay()->toDateString()])->assertUnprocessable();
        $id = $this->apiAs($employeeUser, 'POST', '/api/v1/reimbursements', ['amount' => '120.55', 'category' => 'travel', 'description' => 'Taxi', 'expense_date' => now()->subDay()->toDateString(), 'receipt_id' => $receipt])
            ->assertCreated()->assertJsonPath('data.amount.currency', 'USD')->json('data.id');

        $this->apiAs($employeeUser, 'POST', "/api/v1/reimbursements/{$id}/approve")->assertForbidden();
        $this->apiAs($peerUser, 'POST', "/api/v1/reimbursements/{$id}/approve")->assertNotFound();
        $this->apiAs($peerUser, 'GET', "/api/v1/reimbursements/{$id}/receipt")->assertNotFound();
        $this->apiAs($managerUser, 'GET', '/api/v1/reimbursements?scope=approvals')->assertOk()->assertJsonPath('data.0.can_decide', true);
        $this->apiAs($managerUser, 'GET', "/api/v1/reimbursements/{$id}/receipt")->assertOk();
        $this->apiAs($managerUser, 'POST', "/api/v1/reimbursements/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->apiAs($managerUser, 'POST', "/api/v1/reimbursements/{$id}/approve")->assertConflict();

        $run = $this->startRun($periods[0]);
        $this->assertSame('120.55', $this->line($this->slips($run)[0], 'REIMB')['amount']['amount']);
        $this->apiAs($this->preparer, 'POST', "/api/v1/payroll/runs/{$run}/submit")->assertOk();
        $this->apiAs($this->approver, 'POST', "/api/v1/payroll/runs/{$run}/approve")->assertOk();
        $this->assertSame('paid', Tenancy::run($this->tenant, fn () => Reimbursement::query()->first()->status));
        $this->assertNull($this->line($this->slips($this->startRun($periods[1]))[0], 'REIMB'));
    }

    public function test_structure_components_cover_only_the_structure_days_and_entered_inputs_clear_issues(): void
    {
        [, $e] = $this->staff();
        [, $periods] = $this->calendar(1);
        $structure = $this->structure();
        $this->pay($e);
        $this->pay($e, '60000.00', '2027-01-17', $structure);
        $run = $this->startRun($periods[0]);
        $slip = fn () => collect($this->slips($run))->firstWhere('employee.employee_number', $e->employee_number);
        $s = $slip();
        // Structure covers 15 of 31 days: HOUSING 500.00 × 15/31 = 241.935 → 241.94; PENSION 5% of 2419.35 = 120.9675 → 120.97.
        $this->assertSame('241.94', $this->line($s, 'HOUSING')['amount']['amount']);
        $this->assertSame('15/31', $this->line($s, 'HOUSING')['basis']['prorated_days']);
        $this->assertSame('120.97', $this->line($s, 'PENSION')['amount']['amount']);
        $this->assertSame(['awaiting_input'], array_column($s['issues'], 'code'));
        $this->assertArrayNotHasKey('awaiting_inputs', $s['employee']);

        $line = $this->apiAs($this->preparer, 'POST', "/api/v1/payroll/runs/{$run}/payslips/{$s['id']}/lines", ['code' => 'fica', 'name' => 'FICA', 'kind' => 'deduction',
            'amount' => '100.00', 'reason' => 'From provider worksheet'])->assertSuccessful()->json('data.id');
        $this->assertSame([], $slip()['issues']);
        $this->apiAs($this->preparer, 'DELETE', "/api/v1/payroll/runs/{$run}/lines/{$line}")->assertSuccessful();
        $this->assertSame(['awaiting_input'], array_column($slip()['issues'], 'code'));
        $this->apiAs($this->preparer, 'POST', "/api/v1/payroll/runs/{$run}/prepare")->assertOk();
        $this->assertSame(['awaiting_input'], array_column($slip()['issues'], 'code'));
    }

    public function test_country_rules_bound_configuration_and_statutory_items_are_never_calculated(): void
    {
        $this->apiAs($this->owner, 'POST', '/api/v1/payroll/calendars', ['legal_entity' => $this->us->public_id, 'name' => 'Weekly', 'pay_frequency' => 'weekly',
            'first_period_start' => '2027-01-04', 'approval_levels' => 1])->assertUnprocessable()->assertJsonValidationErrors('pay_frequency');
        $mx = $this->entity($this->tenant, 'MX');
        $this->apiAs($this->owner, 'POST', '/api/v1/payroll/calendars', ['legal_entity' => $mx->public_id, 'name' => 'MX', 'pay_frequency' => 'monthly',
            'first_period_start' => '2027-01-01', 'approval_levels' => 1])->assertUnprocessable()->assertJsonValidationErrors('pay_frequency');
        $this->apiAs($this->owner, 'POST', '/api/v1/payroll/calendars', ['legal_entity' => $this->us->public_id, 'name' => 'Four', 'pay_frequency' => 'monthly',
            'first_period_start' => '2027-01-01', 'approval_levels' => 4])->assertUnprocessable();
        $s = $this->apiAs($this->owner, 'POST', '/api/v1/payroll/structures', ['legal_entity' => $this->us->public_id, 'name' => 'S', 'code' => 'S'])->json('data.id');
        $this->apiAs($this->owner, 'POST', "/api/v1/payroll/structures/{$s}/components", ['code' => 'FICA', 'name' => 'FICA', 'kind' => 'deduction', 'method' => 'percent_of_base', 'rate_percent' => 7.65, 'rule_key' => 'us_fica', 'effective_from' => '2027-01-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('method');
        $this->apiAs($this->owner, 'POST', "/api/v1/payroll/structures/{$s}/components", ['code' => 'X', 'name' => 'X', 'kind' => 'deduction', 'method' => 'provider', 'rule_key' => 'invented', 'effective_from' => '2027-01-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('rule_key');
        $this->apiAs($this->owner, 'POST', "/api/v1/payroll/structures/{$s}/components", ['code' => 'FICA', 'name' => 'FICA', 'kind' => 'deduction', 'method' => 'provider', 'rule_key' => 'us_fica', 'effective_from' => '2027-01-01'])
            ->assertCreated()->assertJsonPath('data.source', 'provider');
    }

    public function test_provider_import_is_labelled_and_exports_are_restricted_and_safe(): void
    {
        [, $employee] = $this->staff();
        [, $periods] = $this->calendar(1);
        $this->pay($employee);
        $run = $this->startRun($periods[0]);
        $bad = "employee_number,code,name,kind,amount,reference\nNOPE,FIT,Federal income tax,deduction,10.00,R1\n";
        $this->apiAs($this->preparer, 'POST', "/api/v1/payroll/runs/{$run}/provider-import", ['file' => UploadedFile::fake()->createWithContent('r.csv', $bad)])->assertUnprocessable();
        $csv = "employee_number,code,name,kind,amount,reference\n{$employee->employee_number},FIT,=HYPERLINK(\"x\"),deduction,612.34,PRV-1\n";
        $this->apiAs($this->preparer, 'POST', "/api/v1/payroll/runs/{$run}/provider-import", ['file' => UploadedFile::fake()->createWithContent('r.csv', $csv)])->assertOk()->assertJsonPath('data.lines', 1);
        $line = $this->line($this->slips($run)[0], 'FIT');
        $this->assertSame(['provider', 'generic_csv', 'PRV-1', '612.34'], [$line['source'], $line['provider'], $line['provider_reference'], $line['amount']['amount']]);
        // Re-importing replaces rather than duplicates provider lines.
        $this->apiAs($this->preparer, 'POST', "/api/v1/payroll/runs/{$run}/provider-import", ['file' => UploadedFile::fake()->createWithContent('r.csv', $csv)])->assertOk();
        $this->assertCount(1, collect($this->slips($run)[0]['lines'])->where('code', 'FIT'));

        $register = $this->apiAs($this->preparer, 'GET', "/api/v1/payroll/runs/{$run}/export?format=register")->assertOk()->getContent();
        $this->assertStringContainsString("'=HYPERLINK", $register);
        $this->assertStringContainsString(',provider,612.34,', $register);
        $this->assertStringContainsString(',calculated,5000.00,', $register);
        $this->apiAs($this->preparer, 'GET', "/api/v1/payroll/runs/{$run}/export?format=provider")->assertOk();
        $hrManager = $this->makeUser($this->tenant, 'hr-manager');
        $this->apiAs($hrManager, 'GET', "/api/v1/payroll/runs/{$run}/export?format=summary")->assertForbidden();
        $this->assertSame(2, $this->audit()->where('event', 'payroll.provider_imported')->count());
        $this->assertSame(2, $this->audit()->where('event', 'payroll.exported')->count());
    }

    public function test_access_is_restricted_by_role_and_tenant(): void
    {
        [$employeeUser, $employee] = $this->staff();
        [, $periods] = $this->calendar(1);
        $this->pay($employee);
        $run = $this->startRun($periods[0]);
        $slip = $this->slips($run)[0]['id'];

        foreach (['hr-administrator', 'hr-manager', 'department-manager', 'employee', 'auditor'] as $role) {
            $user = $this->makeUser($this->tenant, $role);
            $this->apiAs($user, 'GET', '/api/v1/payroll/runs')->assertForbidden();
            $this->apiAs($user, 'GET', "/api/v1/payroll/runs/{$run}")->assertForbidden();
            $this->apiAs($user, 'GET', '/api/v1/payroll/compensation')->assertForbidden();
            $this->apiAs($user, 'GET', "/api/v1/employees/{$employee->public_id}/compensation-history")->assertForbidden();
            $this->apiAs($user, 'POST', '/api/v1/payroll/runs', ['period' => $periods[1], 'type' => 'regular'])->assertForbidden();
        }
        $this->apiAs($employeeUser, 'GET', "/api/v1/payroll/payslips/{$slip}")->assertForbidden();

        $other = $this->makeTenant();
        $foreign = $this->makeUser($other, 'payroll-administrator');
        $this->apiAs($foreign, 'GET', "/api/v1/payroll/runs/{$run}")->assertNotFound();
        $this->apiAs($foreign, 'GET', "/api/v1/payroll/payslips/{$slip}/pdf")->assertNotFound();
        $this->apiAs($foreign, 'POST', "/api/v1/payroll/runs/{$run}/approve")->assertNotFound();
        $this->apiAs($foreign, 'POST', "/api/v1/employees/{$employee->public_id}/compensation-history", ['amount' => '1.00', 'basis' => 'annual', 'pay_frequency' => 'monthly', 'effective_from' => '2028-01-01', 'reason' => 'x x'])->assertNotFound();
        $this->apiAs($foreign, 'POST', '/api/v1/payroll/runs', ['period' => $periods[1], 'type' => 'regular'])->assertUnprocessable();
        $this->apiAs($foreign, 'GET', '/api/v1/payroll/runs')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_one_regular_run_per_period_and_off_cycle_runs_carry_no_base_salary(): void
    {
        [, $employee] = $this->staff();
        [, $periods] = $this->calendar(1);
        $this->pay($employee);
        $this->startRun($periods[0]);
        $this->apiAs($this->preparer, 'POST', '/api/v1/payroll/runs', ['period' => $periods[0], 'type' => 'regular'])->assertConflict();
        $off = $this->startRun($periods[0], 'off_cycle');
        $this->assertNull($this->line($this->slips($off)[0], 'BASE'));
        $this->apiAs($this->preparer, 'POST', "/api/v1/payroll/runs/{$off}/submit")->assertUnprocessable();
    }

    public function test_compensation_history_is_effective_dated_and_keeps_legacy_record_in_step(): void
    {
        [, $employee] = $this->staff();
        $this->pay($employee, '60000.00', '2026-01-01');
        $this->pay($employee, '66000.00', '2026-07-01');
        $this->apiAs($this->preparer, 'POST', "/api/v1/employees/{$employee->public_id}/compensation-history", ['amount' => '1.00', 'basis' => 'annual', 'pay_frequency' => 'monthly', 'effective_from' => '2026-03-01', 'reason' => 'Backdate'])
            ->assertUnprocessable()->assertJsonValidationErrors('effective_from');
        $this->apiAs($this->preparer, 'POST', "/api/v1/employees/{$employee->public_id}/compensation-history", ['amount' => '1.001', 'basis' => 'annual', 'pay_frequency' => 'monthly', 'effective_from' => '2026-09-01', 'reason' => 'Bad'])
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
        $history = $this->apiAs($this->approver, 'GET', "/api/v1/employees/{$employee->public_id}/compensation-history")->assertOk()->json('data');
        $this->assertSame(['2026-07-01', null], [$history[0]['effective_from'], $history[0]['effective_to']]);
        $this->assertSame(['2026-01-01', '2026-06-30'], [$history[1]['effective_from'], $history[1]['effective_to']]);
        $this->assertSame('USD', $history[0]['amount']['currency']);
        $legacy = Tenancy::run($this->tenant, fn () => EmployeeCompensation::query()->where('employee_id', $employee->id)->first());
        $this->assertSame('66000.00', (string) $legacy->base_salary);
        $this->apiAs($this->approver, 'GET', '/api/v1/payroll/compensation?date=2026-02-01')->assertOk()->assertJsonPath('data.0.current.amount.amount', '60000.00');
    }
}
