<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use HasUuids, SoftDeletes;

    protected $guarded = [];

    protected $casts = ['blueprint' => 'array'];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function docTemplate()
    {
        return $this->belongsTo(DocTemplate::class);
    }

    public function inputs()
    {
        return $this->hasMany(ProjectInput::class);
    }

    public function understanding()
    {
        return $this->hasOne(Understanding::class);
    }

    public function interviewItems()
    {
        return $this->hasMany(InterviewItem::class)->orderBy('seq');
    }

    public function structureNodes()
    {
        return $this->hasMany(StructureNode::class)->orderBy('sort');
    }

    public function stackChoices()
    {
        return $this->hasMany(StackChoice::class);
    }

    public function generationRuns()
    {
        return $this->hasMany(GenerationRun::class)->latest();
    }

    public function assistantMessages()
    {
        return $this->hasMany(AssistantMessage::class);
    }

    public function shareLinks()
    {
        return $this->hasMany(ShareLink::class);
    }

    public function changeRequests()
    {
        return $this->hasMany(ChangeRequest::class);
    }

    public function baselines()
    {
        return $this->hasMany(Baseline::class);
    }

    public function documents()
    {
        return $this->hasMany(Document::class);
    }

    public function healthFindings()
    {
        return $this->hasMany(HealthFinding::class);
    }

    public function openQuestions()
    {
        return $this->hasMany(OpenQuestion::class);
    }

    /**
     * Sinkronkan open questions dari sumber derived (interview skip, asumsi,
     * kontradiksi input). Idempoten via question_hash; item answered tidak disentuh.
     */
    public function syncOpenQuestions(): void
    {
        $sources = [
            'interview' => $this->interviewItems()->where('skipped', true)->orderBy('seq')->pluck('question')->all(),
            'assumption' => $this->understanding?->assumptions ?? [],
            'contradiction' => $this->understanding?->contradictions ?? [],
        ];
        foreach ($sources as $source => $questions) {
            foreach ($questions as $q) {
                if (! is_string($q) || $q === '') {
                    continue;
                }
                $this->openQuestions()->firstOrCreate(
                    ['question_hash' => sha1("$source|$q")],
                    ['source' => $source, 'question' => $q],
                );
            }
        }
    }

    public function estimates()
    {
        return $this->hasMany(Estimate::class);
    }

    public function assumptions(): array
    {
        $fromInterview = $this->interviewItems()->where('skipped', true)->pluck('assumption_text')->filter()->values()->all();
        $fromUnderstanding = $this->understanding?->assumptions ?? [];

        return array_values(array_filter(array_merge($fromUnderstanding, $fromInterview)));
    }

    /**
     * FR-12: bahasa utama dokumen ('bilingual' dianggap id).
     * BUGFIX: kolom projects.language TIDAK PERNAH ditulis aplikasi (selalu default 'id') —
     * bahasa nyata proyek ada di blueprint['language'] (ditulis WizardController::saveInput),
     * fallback ke bahasa template perusahaan (FR-16). Rantai sama dengan SpecEngine::generateDocument.
     */
    public function primaryLanguage(): string
    {
        return ($this->blueprint['language'] ?? $this->docTemplate?->language ?? 'id') === 'en' ? 'en' : 'id';
    }
}
