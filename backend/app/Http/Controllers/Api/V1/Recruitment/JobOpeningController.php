<?php

namespace App\Http\Controllers\Api\V1\Recruitment;

use App\Audit\AuditEvents;
use App\Country\RuleResolver;
use App\Http\Controllers\Api\V1\Concerns\PresentsRecruitment;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JobApplication;
use App\Models\JobOpening;
use App\Models\LegalEntity;
use App\Models\RecruitmentSetting;
use App\Models\WorkLocation;
use App\Recruitment\RecruitmentAccess;
use App\Security\Permission as P;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class JobOpeningController extends Controller
{
    use PresentsRecruitment;

    private const WITH = ['legalEntity', 'department', 'workLocation', 'hiringManager'];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission(P::RecruitmentView), 403);
        $data = $request->validate(['status' => ['sometimes', Rule::in(JobOpening::STATUSES)], 'q' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'between:1,100']]);
        $page = RecruitmentAccess::openings($user)->with(self::WITH)
            ->withCount(['applications', 'applications as active_count' => fn ($q) => $q->where('status', 'active')])
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($data['q'] ?? null, fn ($q, $s) => $q->where('title', 'like', '%'.addcslashes($s, '%_\\').'%'))
            ->orderByRaw("case status when 'published' then 0 when 'draft' then 1 else 2 end")->orderByDesc('id')
            ->paginate((int) ($data['per_page'] ?? 25));

        return response()->json(['data' => $page->getCollection()->map(fn ($o) => $this->presentOpening($o))->values(), 'meta' => $this->meta($page)]);
    }

    public function show(Request $request, JobOpening $opening): JsonResponse
    {
        abort_unless(RecruitmentAccess::openings($request->user())->whereKey($opening->id)->exists(), 404);

        return response()->json(['data' => $this->presentOpening($opening->load(self::WITH)->loadCount('applications'), true)]);
    }

    public function store(Request $request, RuleResolver $rules): JsonResponse
    {
        abort_unless($request->user()->hasPermission(P::RecruitmentManage), 403);
        $data = $request->validate($this->rules(true));
        $opening = new JobOpening;
        $this->apply($opening, $data, $rules);
        $opening->forceFill(['slug' => Str::limit(Str::slug($data['title']), 100, '').'-'.Str::lower(Str::random(6)), 'status' => 'draft',
            'created_by' => $request->user()->id])->save();

        return response()->json(['data' => $this->presentOpening($opening->load(self::WITH), true)], 201);
    }

    public function update(Request $request, JobOpening $opening, RuleResolver $rules): JsonResponse
    {
        abort_unless($request->user()->hasPermission(P::RecruitmentManage), 403);
        abort_if($opening->status === 'closed', 409, __('Closed openings cannot be edited.'));
        $this->apply($opening, $request->validate($this->rules(false)), $rules);
        $opening->save();

        return response()->json(['data' => $this->presentOpening($opening->load(self::WITH), true)]);
    }

    public function publish(Request $request, JobOpening $opening): JsonResponse
    {
        abort_unless($request->user()->hasPermission(P::RecruitmentManage), 403);
        abort_unless($opening->status === 'draft', 409, __('Only draft openings can be published.'));
        $opening->forceFill(['status' => 'published', 'published_at' => now()])->saveQuietly();
        AuditLog::record(AuditEvents::JOB_OPENING_PUBLISHED, $opening, metadata: ['title' => $opening->title]);

        return response()->json(['data' => $this->presentOpening($opening->load(self::WITH), true)]);
    }

    public function close(Request $request, JobOpening $opening): JsonResponse
    {
        abort_unless($request->user()->hasPermission(P::RecruitmentManage), 403);
        abort_if($opening->status === 'closed', 409, __('This opening is already closed.'));
        DB::transaction(function () use ($opening) {
            $opening->forceFill(['status' => 'closed', 'closed_at' => now()])->saveQuietly();
            // The retention clock starts when the opening closes; hired candidates become employee records instead.
            JobApplication::query()->where('job_opening_id', $opening->id)->where('status', '!=', 'hired')->whereNull('retain_until')
                ->update(['retain_until' => now()->addDays(RecruitmentSetting::current()->retention_days)]);
            AuditLog::record(AuditEvents::JOB_OPENING_CLOSED, $opening, metadata: ['title' => $opening->title]);
        });

        return response()->json(['data' => $this->presentOpening($opening->load(self::WITH), true)]);
    }

    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'title' => [$req, 'string', 'max:160'],
            'legal_entity_id' => [$req, 'string', 'max:26'],
            'department_id' => ['sometimes', 'nullable', 'string', 'max:26'],
            'work_location_id' => ['sometimes', 'nullable', 'string', 'max:26'],
            'hiring_manager_id' => ['sometimes', 'nullable', 'string', 'max:26'],
            'employment_type' => ['sometimes', 'nullable', 'string', 'max:40'],
            'workplace' => ['sometimes', Rule::in(JobOpening::WORKPLACES)],
            'openings' => ['sometimes', 'integer', 'between:1,500'],
            'summary' => ['sometimes', 'nullable', 'string', 'max:500'],
            'description' => [$req, 'string', 'min:20', 'max:20000'],
            'requirements' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'closes_on' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
        ];
    }

    private function apply(JobOpening $opening, array $data, RuleResolver $rules): void
    {
        $opening->fill(array_intersect_key($data, array_flip($opening->getFillable())));
        $refs = ['legal_entity_id' => LegalEntity::class, 'department_id' => Department::class, 'work_location_id' => WorkLocation::class, 'hiring_manager_id' => Employee::class];
        foreach ($refs as $field => $class) {
            if (array_key_exists($field, $data)) {
                $opening->{$field} = $data[$field] === null ? null : $this->ref($class, $data[$field], $field)->getKey();
            }
        }
        $location = $opening->work_location_id ? WorkLocation::query()->find($opening->work_location_id) : null;
        if ($location && (int) $location->legal_entity_id !== (int) $opening->legal_entity_id) {
            throw ValidationException::withMessages(['work_location_id' => __('The work location belongs to another legal entity.')]);
        }
        if ($location && $opening->employment_type) {
            $allowed = $rules->employmentTypes($location, now()->toDateString());
            if ($allowed !== [] && ! in_array($opening->employment_type, $allowed, true)) {
                throw ValidationException::withMessages(['employment_type' => __('This employment type is not configured for the work location.')]);
            }
        }
    }

    /** @param class-string<Model> $class */
    private function ref(string $class, string $publicId, string $field): Model
    {
        return $class::query()->where('public_id', $publicId)->first()
            ?? throw ValidationException::withMessages([$field => __('The selected :attribute is invalid.', ['attribute' => str_replace('_', ' ', $field)])]);
    }
}
