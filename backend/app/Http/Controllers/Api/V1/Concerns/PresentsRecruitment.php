<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\Candidate;
use App\Models\CandidateDocument;
use App\Models\Interview;
use App\Models\InterviewFeedback;
use App\Models\JobApplication;
use App\Models\JobOffer;
use App\Models\JobOpening;
use App\Models\PipelineStage;
use App\Models\User;
use App\Payroll\Money;
use App\Recruitment\RecruitmentAccess;
use App\Security\Permission as P;

trait PresentsRecruitment
{
    use ResolvesTimeEmployees;

    protected function presentOpening(JobOpening $o, bool $detail = false): array
    {
        return [
            'id' => $o->public_id,
            'title' => $o->title,
            'slug' => $o->slug,
            'status' => $o->status,
            'workplace' => $o->workplace,
            'employment_type' => $o->employment_type,
            'openings' => $o->openings,
            'summary' => $o->summary,
            'description' => $detail ? $o->description : null,
            'requirements' => $detail ? $o->requirements : null,
            'closes_on' => $o->closes_on?->toDateString(),
            'published_at' => $o->published_at?->toIso8601String(),
            'closed_at' => $o->closed_at?->toIso8601String(),
            'legal_entity' => $o->legalEntity ? ['id' => $o->legalEntity->public_id, 'name' => $o->legalEntity->name, 'country_code' => $o->legalEntity->country_code,
                'currency_code' => $o->legalEntity->currency_code] : null,
            'department' => $o->department ? ['id' => $o->department->public_id, 'name' => $o->department->name] : null,
            'work_location' => $o->workLocation ? ['id' => $o->workLocation->public_id, 'name' => $o->workLocation->name, 'city' => $o->workLocation->city] : null,
            'hiring_manager' => $this->person($o->hiringManager),
            'applications_count' => $o->applications_count ?? null,
            'active_count' => $o->active_count ?? null,
        ];
    }

    protected function presentStage(PipelineStage $s): array
    {
        return ['id' => $s->public_id, 'key' => $s->key, 'name' => $s->name, 'kind' => $s->kind, 'sort_order' => $s->sort_order];
    }

    protected function presentCandidate(Candidate $c): array
    {
        return ['id' => $c->public_id, 'name' => $c->fullName(), 'first_name' => $c->first_name, 'last_name' => $c->last_name, 'email' => $c->email, 'phone' => $c->phone,
            'location' => $c->location, 'linkedin_url' => $c->linkedin_url, 'source' => $c->source, 'on_hold' => $c->on_hold, 'anonymized' => $c->anonymized_at !== null];
    }

    protected function presentDocument(CandidateDocument $d): array
    {
        return ['id' => $d->public_id, 'kind' => $d->kind, 'suggested_kind' => $d->suggested_kind, 'name' => $d->file?->original_name, 'mime_type' => $d->file?->mime_type,
            'size' => $d->file?->size, 'uploaded_at' => $d->created_at?->toIso8601String()];
    }

    protected function presentApplication(JobApplication $a, User $user, bool $detail = false): array
    {
        $offer = $a->relationLoaded('offers') ? $a->offers->sortByDesc('id')->first() : null;

        return [
            'id' => $a->public_id,
            'status' => $a->status,
            'version' => $a->version,
            'position' => $a->position,
            'stage' => $a->stage ? $this->presentStage($a->stage) : null,
            'opening' => $a->opening ? ['id' => $a->opening->public_id, 'title' => $a->opening->title, 'status' => $a->opening->status] : null,
            'candidate' => $a->candidate ? $this->presentCandidate($a->candidate) : null,
            'applied_at' => $a->created_at?->toIso8601String(),
            'consent' => ['version' => $a->consent_version, 'given_at' => $a->consented_at?->toIso8601String()],
            'retain_until' => $a->retain_until?->toIso8601String(),
            'rejection_reason' => $detail ? $a->rejection_reason : null,
            'cover_letter' => $detail ? $a->cover_letter : null,
            'employee' => $this->person($a->employee),
            'latest_offer_status' => $offer?->status,
            'documents' => $detail ? $a->documents->map(fn ($d) => $this->presentDocument($d))->values() : null,
            'can' => [
                'act' => RecruitmentAccess::canAct($user, $a),
                'manage' => RecruitmentAccess::manages($user),
                'offers' => $user->hasPermission(P::RecruitmentOffers),
            ],
        ];
    }

    protected function presentInterview(Interview $i, User $user): array
    {
        $mine = $i->feedback->firstWhere('user_id', $user->id);
        // Panelists see colleagues' feedback only after submitting their own, so opinions stay independent.
        $seesAll = RecruitmentAccess::manages($user) || $mine !== null;

        return [
            'id' => $i->public_id,
            'title' => $i->title,
            'starts_at' => $i->starts_at->toIso8601String(),
            'ends_at' => $i->ends_at->toIso8601String(),
            'timezone' => $i->timezone,
            'mode' => $i->mode,
            'location' => $i->location,
            'status' => $i->status,
            'application' => $i->application ? ['id' => $i->application->public_id, 'candidate' => $i->application->candidate?->fullName(),
                'opening' => $i->application->opening?->title] : null,
            'panel' => $i->panel->map(fn (User $u) => ['id' => $u->public_id, 'name' => $u->name, 'submitted' => $i->feedback->contains('user_id', $u->id)])->values(),
            'is_panelist' => $i->panel->contains('id', $user->id),
            'my_feedback' => $mine ? $this->presentFeedback($mine) : null,
            'feedback' => $seesAll ? $i->feedback->map(fn ($f) => $this->presentFeedback($f))->values() : null,
        ];
    }

    protected function presentFeedback(InterviewFeedback $f): array
    {
        return ['id' => $f->public_id, 'author' => $f->author?->name, 'rating' => $f->rating, 'recommendation' => $f->recommendation, 'strengths' => $f->strengths,
            'concerns' => $f->concerns, 'notes' => $f->notes, 'submitted_at' => $f->created_at?->toIso8601String()];
    }

    protected function presentOffer(JobOffer $o): array
    {
        return [
            'id' => $o->public_id,
            'status' => $o->status,
            'job_title' => $o->job_title,
            'base_salary' => ['amount' => Money::toDecimal($o->base_salary_minor, $o->currency), 'currency' => $o->currency],
            'pay_frequency' => $o->pay_frequency,
            'start_date' => $o->start_date?->toDateString(),
            'expires_on' => $o->expires_on?->toDateString(),
            'notes' => $o->notes,
            'letter' => $o->letter ? ['name' => $o->letter->original_name, 'size' => $o->letter->size] : null,
            'approved_by' => $o->approver?->name,
            'approved_at' => $o->approved_at?->toIso8601String(),
            'sent_at' => $o->sent_at?->toIso8601String(),
            'responded_at' => $o->responded_at?->toIso8601String(),
            'next' => JobOffer::TRANSITIONS[$o->status],
        ];
    }
}
