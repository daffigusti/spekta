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
}
