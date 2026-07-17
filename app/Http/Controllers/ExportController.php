<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Project;
use App\Services\Estimator;
use App\Services\Exporter;
use App\Services\ProposalGenerator;
use App\Services\RabExporter;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ExportController extends Controller
{
    public function download(Request $request, Project $project, string $kind, Exporter $exporter)
    {
        ProjectController::authorizeProject($request, $project);
        abort_unless(in_array($kind, ['zip', 'agent_pack', 'proposal', 'rab']), 404);

        if (in_array($kind, ['proposal', 'rab'])) {
            return $this->presales($request, $project, $kind);
        }

        $path = $exporter->zip($project, $kind);
        $name = Str::slug($project->name).'-'.($kind === 'zip' ? 'blueprint' : 'agent-pack').'.zip';

        return response()->download($path, $name)->deleteFileAfterSend();
    }

    /** FR-16: proposal DOCX / RAB Excel dari estimasi scope aktif (?scope=mvp|full, default full). */
    private function presales(Request $request, Project $project, string $kind)
    {
        $scope = $request->query('scope', 'full');
        abort_unless(in_array($scope, ['mvp', 'full']), 404);

        $estimate = $project->estimates()->with('lines.structureNode')->where('scope', $scope)->first()
            ?? app(Estimator::class)->compute($project, $scope);
        $estimate->loadMissing('lines.structureNode');

        if ($kind === 'proposal') {
            // BR-23: snapshot terkunci saat proposal digenerate + jejak audit
            $estimate->update(['status' => 'snapshotted']);
            AuditLog::create([
                'workspace_id' => $project->workspace_id,
                'actor_id' => $request->user()->id,
                'action' => 'proposal.generated',
                'entity_type' => 'estimate',
                'entity_id' => $estimate->id,
            ]);
            $path = app(ProposalGenerator::class)->generate($project, $estimate);
            $name = Str::slug($project->name)."-proposal-{$scope}.docx";
        } else {
            $path = app(RabExporter::class)->generate($project, $estimate);
            $name = Str::slug($project->name)."-rab-{$scope}.xlsx";
        }

        return response()->download($path, $name)->deleteFileAfterSend();
    }
}
