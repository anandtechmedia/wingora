<?php

namespace Tests\Feature\Security;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Tenant;
use App\Security\RoleCatalog;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class HardeningTest extends SecurityTestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant();
        Storage::fake('private');
    }

    public function test_security_headers_are_present_on_api_responses(): void
    {
        $response = $this->getJson('/api/v1/auth/me');
        foreach (['Content-Security-Policy', 'X-Frame-Options', 'X-Content-Type-Options', 'Referrer-Policy', 'Permissions-Policy', 'X-Request-Id'] as $header) {
            $response->assertHeader($header);
        }
        $response->assertHeader('X-Frame-Options', 'DENY');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertFalse($response->headers->has('X-Powered-By'));
    }

    public function test_cors_only_allows_configured_origins(): void
    {
        $allowed = $this->withHeaders(['Origin' => 'http://localhost:5173', 'Access-Control-Request-Method' => 'GET'])->options('/api/v1/auth/me');
        $this->assertSame('http://localhost:5173', $allowed->headers->get('Access-Control-Allow-Origin'));
        $this->assertSame('true', $allowed->headers->get('Access-Control-Allow-Credentials'));

        $denied = $this->withHeaders(['Origin' => 'https://evil.example', 'Access-Control-Request-Method' => 'GET'])->options('/api/v1/auth/me');
        $this->assertNotSame('https://evil.example', $denied->headers->get('Access-Control-Allow-Origin'));
        $this->assertNotSame('*', $denied->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_mass_assignment_of_protected_attributes_is_rejected(): void
    {
        $this->expectException(MassAssignmentException::class);
        Tenancy::run($this->tenant, fn () => new Employee(['first_name' => 'A', 'tenant_id' => 999, 'national_id' => 'x']));
    }

    public function test_unexpected_request_fields_cannot_change_protected_attributes(): void
    {
        $owner = $this->makeUser($this->tenant, RoleCatalog::ORGANIZATION_OWNER);
        $this->apiAs($owner, 'PATCH', '/api/v1/organization', ['name' => 'Acme', 'status' => 'suspended', 'id' => 1, 'mfa_required' => true])->assertOk();

        $fresh = $this->tenant->fresh();
        $this->assertSame('Acme', $fresh->name);
        $this->assertSame('active', $fresh->status);
        $this->assertFalse($fresh->mfa_required);
    }

    public function test_search_input_is_parameterised_against_sql_injection(): void
    {
        $owner = $this->makeUser($this->tenant, RoleCatalog::ORGANIZATION_OWNER);
        $this->makeEmployee($this->tenant);
        $this->makeEmployee($this->makeTenant());

        foreach (["' OR '1'='1", "%' OR 1=1 --", "x'); DROP TABLE employees; --", '%'] as $payload) {
            $this->apiAs($owner, 'GET', '/api/v1/employees?search='.urlencode($payload))->assertOk()->assertJsonCount(0, 'data');
        }
        $this->assertSame(2, DB::table('employees')->count());
    }

    public function test_validation_rejects_bad_input_with_problem_json(): void
    {
        $owner = $this->makeUser($this->tenant, RoleCatalog::ORGANIZATION_OWNER);
        $this->apiAs($owner, 'GET', '/api/v1/employees?per_page=100000')->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/problem+json')->assertJsonValidationErrors('per_page');
        $this->apiAs($owner, 'GET', '/api/v1/employees/not-a-ulid')->assertNotFound();
        $this->apiAs($owner, 'POST', '/api/v1/users', ['email' => 'not-an-email'])->assertUnprocessable();
    }

    public function test_internal_ids_and_secrets_are_never_serialised(): void
    {
        $user = $this->makeUser($this->tenant, RoleCatalog::HR_ADMIN);
        $json = $this->apiAs($user, 'GET', '/api/v1/auth/me')->assertOk()->getContent();
        foreach (['password', 'mfa_secret', 'remember_token', 'tenant_id', 'recovery'] as $needle) {
            $this->assertStringNotContainsString($needle, $json);
        }
        $this->assertStringNotContainsString('"id":'.$user->id.',', $json);
    }

    public function test_output_is_json_encoded_not_rendered_as_html(): void
    {
        $owner = $this->makeUser($this->tenant, RoleCatalog::ORGANIZATION_OWNER);
        $response = $this->apiAs($owner, 'PATCH', '/api/v1/organization', ['name' => '<script>alert(1)</script>'])->assertOk();
        $response->assertHeader('Content-Type', 'application/json');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame('<script>alert(1)</script>', $response->json('data.name'));
    }

    public function test_sensitive_fields_are_encrypted_at_rest_and_redacted_from_audit(): void
    {
        $hr = $this->makeUser($this->tenant, RoleCatalog::HR_ADMIN);
        $emp = $this->makeEmployee($this->tenant, null, null, ['national_id' => '123-45-6789', 'bank_account_number' => 'GB00BANK1234']);

        $raw = DB::table('employees')->where('id', $emp->id)->first();
        $this->assertStringNotContainsString('123-45-6789', $raw->national_id);
        $this->assertStringNotContainsString('GB00BANK1234', $raw->bank_account_number);

        $this->apiAs($hr, 'GET', "/api/v1/employees/{$emp->public_id}/sensitive")->assertOk()->assertJsonPath('data.national_id', '123-45-6789');
        $this->assertDatabaseHas('audit_logs', ['event' => 'employee.sensitive_viewed', 'subject_id' => $emp->id]);
        $this->assertStringNotContainsString('123-45-6789', DB::table('audit_logs')->pluck('new_values')->implode(' '));
    }

    public function test_audit_logs_are_immutable_and_tenant_scoped(): void
    {
        $other = $this->makeTenant();
        $this->makeEmployee($other);
        $auditor = $this->makeUser($this->tenant, RoleCatalog::AUDITOR);

        $rows = $this->apiAs($auditor, 'GET', '/api/v1/audit-logs?per_page=100')->assertOk()->json('data');
        $otherTenantRows = DB::table('audit_logs')->where('tenant_id', $other->id)->pluck('id')->all();
        $this->assertNotEmpty($otherTenantRows);
        $this->assertEmpty(array_intersect(DB::table('audit_logs')->where('tenant_id', $other->id)->pluck('public_id')->all(), array_column($rows, 'id')));

        $log = Tenancy::run($this->tenant, fn () => AuditLog::query()->first());
        $this->expectException(\LogicException::class);
        $log->delete();
    }

    public function test_security_events_are_logged_for_failed_logins(): void
    {
        $user = $this->makeUser($this->tenant, RoleCatalog::EMPLOYEE);
        $this->postJson('/api/v1/auth/tokens', ['email' => $user->email, 'password' => 'nope', 'device_name' => 'x']);
        $this->assertDatabaseHas('security_events', ['type' => 'auth.login_failed', 'user_id' => $user->id, 'tenant_id' => $this->tenant->id]);
    }

    public function test_files_are_private_and_authorised_per_download(): void
    {
        $empUser = $this->makeUser($this->tenant, RoleCatalog::EMPLOYEE);
        $self = $this->makeEmployee($this->tenant, null, $empUser);
        $colleagueUser = $this->makeUser($this->tenant, RoleCatalog::EMPLOYEE);
        $this->makeEmployee($this->tenant, null, $colleagueUser);
        $payroll = $this->makeUser($this->tenant, RoleCatalog::PAYROLL_ADMIN);

        $fileId = $this->apiAs($payroll, 'POST', '/api/v1/files', [
            'file' => UploadedFile::fake()->createWithContent('payslip.pdf', "%PDF-1.4\n%%EOF"),
            'category' => 'payroll',
            'employee_id' => $self->public_id,
        ])->assertCreated()->assertJsonMissingPath('data.path')->json('data.id');

        $this->apiAs($empUser, 'GET', "/api/v1/files/{$fileId}/download")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->apiAs($colleagueUser, 'GET', "/api/v1/files/{$fileId}/download")->assertForbidden();
        $this->apiAs($this->makeUser($this->tenant, RoleCatalog::HR_MANAGER), 'GET', "/api/v1/files/{$fileId}/download")->assertForbidden();
        $this->apiAs($this->makeUser($this->makeTenant(), RoleCatalog::ORGANIZATION_OWNER), 'GET', "/api/v1/files/{$fileId}/download")->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->getJson("/api/v1/files/{$fileId}/download")->assertUnauthorized();

        $this->assertFalse(config('filesystems.disks.private.serve'));
        $this->assertDatabaseHas('audit_logs', ['event' => 'file.downloaded']);
    }

    public function test_employees_cannot_upload_payroll_files_and_dangerous_types_are_rejected(): void
    {
        $empUser = $this->makeUser($this->tenant, RoleCatalog::EMPLOYEE);
        $self = $this->makeEmployee($this->tenant, null, $empUser);

        $this->apiAs($empUser, 'POST', '/api/v1/files', [
            'file' => UploadedFile::fake()->createWithContent('p.pdf', "%PDF-1.4\n%%EOF"), 'category' => 'payroll', 'employee_id' => $self->public_id,
        ])->assertForbidden();

        $this->apiAs($empUser, 'POST', '/api/v1/files', [
            'file' => $this->realUpload('shell.php', '<?php system($_GET["c"]);'), 'category' => 'general',
        ])->assertUnprocessable();

        $this->apiAs($empUser, 'POST', '/api/v1/files', [
            'file' => $this->realUpload('fake.pdf', '<?php echo 1;'), 'category' => 'general',
        ])->assertUnprocessable();
    }

    /** Unlike UploadedFile::fake(), MIME detection here reads the actual bytes. */
    private function realUpload(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    public function test_there_is_no_gate_before_super_user_bypass(): void
    {
        $platformOwner = $this->makeUser(null, RoleCatalog::PLATFORM_OWNER);
        $this->assertFalse($platformOwner->can('payroll.view'));
        $this->assertFalse($platformOwner->can('employees.view.all'));
        $this->assertTrue($platformOwner->can('platform.tenants.manage'));
    }
}
