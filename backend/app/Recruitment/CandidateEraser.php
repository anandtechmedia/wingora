<?php

namespace App\Recruitment;

use App\Models\AiDraft;
use App\Models\Candidate;
use App\Models\InterviewFeedback;
use App\Models\JobApplication;
use App\Models\JobOffer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Removes a candidate's personal data and documents while keeping de-identified pipeline counts.
 * Used for erasure requests and for the retention job. Hired applications are never erased here.
 */
final class CandidateEraser
{
    public function erase(Candidate $candidate, string $why): void
    {
        DB::transaction(function () use ($candidate, $why) {
            $applications = $candidate->applications()->where('status', '!=', 'hired')->get();
            $ids = $applications->pluck('id');
            foreach ($candidate->documents()->whereIn('job_application_id', $ids)->orWhereNull('job_application_id')->where('candidate_id', $candidate->id)->with('file')->get() as $doc) {
                $file = $doc->file;
                $doc->delete();
                PrivateUpload::destroy($file);
            }
            foreach (JobOffer::query()->whereIn('job_application_id', $ids)->with('letter')->get() as $offer) {
                $letter = $offer->letter;
                $offer->forceFill(['letter_file_id' => null, 'notes' => null])->saveQuietly();
                PrivateUpload::destroy($letter);
            }
            InterviewFeedback::query()->whereIn('interview_id', fn ($q) => $q->select('id')->from('interviews')->whereIn('job_application_id', $ids))
                ->update(['strengths' => null, 'concerns' => null, 'notes' => null]);
            AiDraft::query()->where('subject_type', 'job_application')->whereIn('subject_id', $ids)->delete();
            JobApplication::query()->whereIn('id', $ids)->update(['cover_letter' => null, 'consent_ip_hash' => null, 'rejection_reason' => null]);
            JobApplication::query()->whereIn('id', $ids)->where('status', 'active')->update(['status' => 'withdrawn']);

            if (! $candidate->applications()->where('status', 'hired')->exists()) {
                $candidate->forceFill(['first_name' => 'Erased', 'last_name' => 'candidate', 'email' => 'erased-'.$candidate->public_id.'@invalid.invalid',
                    'email_hash' => hash('sha256', Str::random(40)), 'phone' => null, 'location' => null, 'linkedin_url' => null, 'on_hold' => false,
                    'anonymized_at' => now(), 'source' => $why === 'retention' ? $candidate->source : 'other'])->saveQuietly();
            }
        });
    }
}
