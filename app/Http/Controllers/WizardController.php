<?php

namespace App\Http\Controllers;

use App\Models\CreditLedger;
use App\Models\Project;
use App\Services\GenerationPipeline;
use App\Services\SpecEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

class WizardController extends Controller
{
    public function show(Request $request, Project $project)
    {
        ProjectController::authorizeProject($request, $project);

        return Inertia::render('wizard', [
            'project' => $project->only(['id', 'name', 'client_name', 'status', 'wizard_step', 'scope_mode']),
            'input' => $project->inputs()->latest()->first()?->only(['kind', 'raw_text']),
            'understanding' => $project->understanding,
            'interview' => $project->interviewItems,
            'nodes' => $project->structureNodes,
            'stack' => $project->stackChoices,
            'run' => $run = $project->generationRuns()->with('nodes')->latest()->first(),
            'credits' => $project->workspace->creditBalance(),
            // FR-07 streaming: buffer dokumen yang sedang ditulis (diisi GenerateDocumentJob)
            'stream' => $run && $run->status === 'running'
                ? \Illuminate\Support\Facades\Cache::get('genstream:'.$run->id)
                : null,
        ]);
    }

    // Canvas struktur sebagai halaman sendiri (di luar wizard)
    public function structure(Request $request, Project $project)
    {
        ProjectController::authorizeProject($request, $project);

        return Inertia::render('structure', [
            'project' => $project->only(['id', 'name', 'client_name', 'status', 'wizard_step', 'scope_mode']),
            'nodes' => $project->structureNodes,
        ]);
    }

    // FR-06: stack sebagai halaman sendiri (di luar wizard)
    public function stack(Request $request, Project $project)
    {
        ProjectController::authorizeProject($request, $project);

        return Inertia::render('stack', [
            'project' => $project->only(['id', 'name', 'client_name', 'status', 'wizard_step', 'scope_mode']),
            'stack' => $project->stackChoices,
            'understanding' => $project->understanding,
        ]);
    }

    // Step 1 — FR-01 (subset: teks ide / paste transkrip)
    public function saveInput(Request $request, Project $project, SpecEngine $engine)
    {
        ProjectController::authorizeProject($request, $project);
        $data = $request->validate([
            'name' => 'nullable|string|max:255', // auto dari AI; bisa diubah di step berikutnya
            'client_name' => 'nullable|string|max:255',
            'kind' => 'required|in:idea,transcript,rfp',
            'raw_text' => 'required_without:file|nullable|string|max:100000',
            'file' => 'required_without:raw_text|nullable|file|mimes:txt,md,docx,pdf|max:10240', // FR-01 multi-source
            'language' => 'nullable|in:id,en,bilingual',
            'depth' => 'nullable|in:auto,concise,full',
            'template' => 'nullable|string|max:40',
        ]);

        $fileName = null;
        $rawText = $data['raw_text'] ?? '';
        if ($request->hasFile('file')) {
            try {
                $extracted = app(\App\Services\InputExtractor::class)->extract($request->file('file'));
            } catch (\InvalidArgumentException $e) {
                return back()->withErrors(['file' => $e->getMessage()]);
            }
            $rawText = trim($rawText."\n\n".$extracted);
            $fileName = $request->file('file')->getClientOriginalName();
        }
        if (mb_strlen($rawText) < 50) {
            return back()->withErrors(['raw_text' => 'Input minimal 50 karakter (teks atau isi file).']);
        }

        $project->update([
            'name' => ($data['name'] ?? null) ?: $project->name,
            'client_name' => $data['client_name'] ?? $project->client_name,
            'blueprint' => [
                'language' => $data['language'] ?? 'id',
                'depth' => $data['depth'] ?? 'auto',
                'template' => $data['template'] ?? 'default',
            ],
        ]);
        $project->inputs()->delete();
        $project->inputs()->create(['kind' => $data['kind'], 'raw_text' => $rawText, 'file_path' => $fileName]);

        // FR-02: AI Understanding
        $result = $engine->understand($project);
        $project->understanding()->updateOrCreate([], [
            'roles' => $result['roles'] ?? [],
            'features' => $result['features'] ?? [],
            'domain' => $result['domain'] ?? null,
            'complexity' => $result['complexity'] ?? 3,
            'assumptions' => $result['assumptions'] ?? [],
            'confirmed' => false,
        ]);
        $project->update(['wizard_step' => 'understanding', 'complexity' => $result['complexity'] ?? 3]);

        // Nama proyek auto dari AI — hanya bila user belum memberi nama sendiri
        if (! empty($result['project_name']) && preg_match('/^Proyek \d{2} /', $project->name)) {
            $project->update(['name' => \Illuminate\Support\Str::limit($result['project_name'], 80, '')]);
        }

        return back();
    }

    // Step 2 — konfirmasi/koreksi understanding (FR-02)
    public function confirmUnderstanding(Request $request, Project $project, SpecEngine $engine)
    {
        ProjectController::authorizeProject($request, $project);
        $data = $request->validate([
            'name' => 'nullable|string|max:255',
            'client_name' => 'nullable|string|max:255',
            'roles' => 'array',
            'features' => 'array|min:1',
            'domain' => 'nullable|string|max:255',
            'complexity' => 'integer|min:1|max:5',
            'assumptions' => 'array',
        ]);

        $project->understanding->update(collect($data)->except(['name', 'client_name'])->all() + ['confirmed' => true]);
        $project->update([
            'complexity' => $data['complexity'] ?? $project->complexity,
            'name' => ($data['name'] ?? null) ?: $project->name,
            'client_name' => $data['client_name'] ?? $project->client_name,
        ]);

        // FR-03: susun pertanyaan interview
        $project->interviewItems()->delete();
        foreach (array_slice($engine->interviewQuestions($project), 0, 10) as $i => $q) {
            $project->interviewItems()->create([
                'seq' => $i + 1,
                'question' => $q['question'],
                'reason' => $q['reason'] ?? null,
                'options' => $q['options'] ?? [],
            ]);
        }
        $project->update(['wizard_step' => 'interview']);

        return back();
    }

    // Step 3 — FR-03
    public function answerInterview(Request $request, Project $project)
    {
        ProjectController::authorizeProject($request, $project);
        $data = $request->validate([
            'seq' => 'required|integer',
            'answer' => 'nullable|string',
            'skip' => 'boolean',
        ]);

        $item = $project->interviewItems()->where('seq', $data['seq'])->firstOrFail();
        if ($request->boolean('skip')) {
            $item->update([
                'skipped' => true,
                'answer_text' => null,
                'assumption_text' => 'Asumsi: '.Str::limit($item->question, 80).' — memakai asumsi standar tim.',
            ]);
        } else {
            $item->update(['skipped' => false, 'answer_text' => $data['answer'], 'assumption_text' => null]);
        }

        return back();
    }

    public function finishInterview(Request $request, Project $project, SpecEngine $engine)
    {
        ProjectController::authorizeProject($request, $project);

        if ($request->boolean('skip_all')) {
            $project->interviewItems()->whereNull('answer_text')->where('skipped', false)->get()
                ->each(fn ($i) => $i->update([
                    'skipped' => true,
                    'assumption_text' => 'Asumsi: '.Str::limit($i->question, 80).' — memakai asumsi standar tim.',
                ]));
        }

        // FR-04: bangun struktur awal dari AI
        if (! $project->structureNodes()->exists()) {
            $root = $project->structureNodes()->create(['kind' => 'root', 'title' => $project->name, 'sort' => 0]);
            foreach ($engine->buildStructure($project) as $pi => $phase) {
                $phaseNode = $project->structureNodes()->create([
                    'kind' => 'phase', 'parent_id' => $root->id, 'title' => $phase['title'],
                    'phase_no' => $pi + 1, 'sort' => $pi,
                ]);
                foreach ($phase['features'] ?? [] as $fi => $f) {
                    $featureNode = $project->structureNodes()->create([
                        'kind' => 'feature', 'parent_id' => $phaseNode->id, 'title' => $f['title'],
                        'description' => $f['description'] ?? null, 'scope' => $f['scope'] ?? 'mvp',
                        'est_md' => $f['est_md'] ?? 0, 'sort' => $fi,
                    ]);
                    foreach ($f['subfeatures'] ?? [] as $si => $sub) {
                        $project->structureNodes()->create([
                            'kind' => 'subfeature', 'parent_id' => $featureNode->id,
                            'title' => is_array($sub) ? $sub['title'] : $sub,
                            'description' => is_array($sub) ? ($sub['description'] ?? null) : null,
                            'est_md' => is_array($sub) ? ($sub['est_md'] ?? 0) : 0, 'sort' => $si,
                        ]);
                    }
                }
            }
        }
        $project->update(['wizard_step' => 'structure']);

        return back();
    }

    // Step 4 — FR-04/FR-05 struktur CRUD
    public function nodeStore(Request $request, Project $project)
    {
        ProjectController::authorizeProject($request, $project);
        $data = $request->validate([
            'parent_id' => 'required|uuid',
            'kind' => 'required|in:phase,feature,subfeature',
            'title' => 'required|string|max:255',
            'est_md' => 'numeric|min:0',
        ]);
        $project->structureNodes()->create($data + ['sort' => 99]);

        return back();
    }

    public function nodeUpdate(Request $request, Project $project, string $nodeId)
    {
        ProjectController::authorizeProject($request, $project);
        $node = $project->structureNodes()->findOrFail($nodeId);
        $node->update($request->validate([
            'title' => 'sometimes|string|max:255',
            'scope' => 'sometimes|in:mvp,full,parked',
            'est_md' => 'sometimes|numeric|min:0',
        ]));

        return back();
    }

    public function nodeDestroy(Request $request, Project $project, string $nodeId)
    {
        ProjectController::authorizeProject($request, $project);
        $node = $project->structureNodes()->findOrFail($nodeId);
        // "parkir ide" (FEATURES.md 2) — soft: scope=parked, bukan hapus permanen
        $node->update(['scope' => 'parked']);

        return back();
    }

    public function confirmStructure(Request $request, Project $project, SpecEngine $engine)
    {
        ProjectController::authorizeProject($request, $project);
        $project->update(['scope_mode' => $request->validate(['scope_mode' => 'required|in:mvp,full'])['scope_mode']]);

        // FR-06: rekomendasi stack
        if (! $project->stackChoices()->exists()) {
            foreach ($engine->recommendStack($project) as $layer) {
                $project->stackChoices()->create([
                    'layer' => $layer['layer'],
                    'choice' => $layer['choice'],
                    'justification' => $layer['justification'] ?? null,
                    'alternatives' => $layer['alternatives'] ?? [],
                    'source' => 'ai',
                ]);
            }
        }
        $project->update(['wizard_step' => 'stack']);

        return back();
    }

    // Step 5 — FR-06 override
    public function stackUpdate(Request $request, Project $project, string $layer)
    {
        ProjectController::authorizeProject($request, $project);
        $data = $request->validate(['choice' => 'required|string|max:255', 'justification' => 'nullable|string']);
        $project->stackChoices()->where('layer', $layer)->firstOrFail()
            ->update($data + ['source' => 'user']); // override tercatat sebagai keputusan user (FR-06)

        return back();
    }

    public function confirmStack(Request $request, Project $project)
    {
        ProjectController::authorizeProject($request, $project);
        $project->update(['wizard_step' => 'generate']);

        return back();
    }

    // Step 6 — FR-07 generate
    public function generate(Request $request, Project $project, GenerationPipeline $pipeline)
    {
        ProjectController::authorizeProject($request, $project);
        $workspace = $project->workspace;

        // BR-05: mode read-only setelah grace period habis — generate diblok, export tetap bisa
        if ($workspace->subscription?->effectiveStatus() === 'readonly') {
            return back()->withErrors(['credits' => 'Langganan berakhir — workspace read-only. Perbarui pembayaran untuk melanjutkan (BR-05).']);
        }

        // BR-02: 1 kredit per pipeline penuh
        if ($workspace->creditBalance() < 1) {
            return back()->withErrors(['credits' => 'Kredit blueprint habis. Upgrade paket atau top-up (BR-02).']);
        }

        if ($project->generationRuns()->whereIn('status', ['queued', 'running'])->exists()) {
            return back();
        }

        CreditLedger::create([
            'workspace_id' => $workspace->id,
            'delta' => -1,
            'kind' => 'consume',
            'ref_type' => 'project',
            'ref_id' => $project->id,
            'idempotency_key' => 'consume-'.$project->id.'-'.now()->timestamp,
        ]);

        $pipeline->start($project);

        return back();
    }

    // Generate dokumen lanjutan yang belum ada (set lengkap pipeline)
    public function generateMissing(Request $request, Project $project, GenerationPipeline $pipeline)
    {
        ProjectController::authorizeProject($request, $project);
        $workspace = $project->workspace;

        if ($workspace->subscription?->effectiveStatus() === 'readonly') {
            return back()->withErrors(['credits' => 'Langganan berakhir — workspace read-only (BR-05).']);
        }
        if ($workspace->creditBalance() < 1) {
            return back()->withErrors(['credits' => 'Kredit blueprint habis. Upgrade paket atau top-up (BR-02).']);
        }
        if ($project->generationRuns()->whereIn('status', ['queued', 'running'])->exists()) {
            return back();
        }

        $data = $request->validate(['doc_keys' => 'nullable|array', 'doc_keys.*' => 'string|max:40']);
        $run = $pipeline->startMissing($project, $data['doc_keys'] ?? null);
        if (! $run) {
            return back()->withErrors(['credits' => 'Tidak ada dokumen terpilih yang bisa digenerate.']);
        }

        CreditLedger::create([
            'workspace_id' => $workspace->id,
            'delta' => -1,
            'kind' => 'consume',
            'ref_type' => 'project',
            'ref_id' => $project->id,
            'idempotency_key' => 'consume-missing-'.$project->id.'-'.uniqid(),
        ]);

        return back();
    }

    public function resumeRun(Request $request, Project $project, GenerationPipeline $pipeline)
    {
        ProjectController::authorizeProject($request, $project);
        $run = $project->generationRuns()->whereIn('status', ['paused', 'error'])->firstOrFail();
        $pipeline->resume($run); // kredit tidak terpotong dobel (USER_FLOWS 8)

        return back();
    }

    // Polling status run (pengganti SSE untuk MVP)
    public function runStatus(Request $request, Project $project)
    {
        ProjectController::authorizeProject($request, $project);
        $run = $project->generationRuns()->with('nodes')->latest()->first();

        return response()->json([
            'status' => $run?->status,
            'project_status' => $project->status,
            'nodes' => $run?->nodes->map(fn ($n) => $n->only(['doc_key', 'status', 'attempt', 'error_text'])),
        ]);
    }
}
