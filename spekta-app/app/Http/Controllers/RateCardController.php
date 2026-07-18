<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class RateCardController extends Controller
{
    public function index(Request $request)
    {
        $workspace = $request->user()->currentWorkspace();

        return Inertia::render('ratecards', [
            'rateCards' => $workspace->rateCards()->get(),
            'roleSplit' => \App\Services\Estimator::roleSplit(),
        ]);
    }

    public function update(Request $request, string $rateCardId)
    {
        $workspace = $request->user()->currentWorkspace();
        $card = $workspace->rateCards()->findOrFail($rateCardId);
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'margin_pct' => 'sometimes|numeric|min:0|max:100',
            'roles' => 'sometimes|array|min:1',
            'roles.*.role' => 'required_with:roles|string|max:50',
            'roles.*.daily_rate' => 'required_with:roles|numeric|min:0',
        ]);
        $card->update($data);

        \App\Models\AuditLog::create([
            'workspace_id' => $workspace->id,
            'actor_id' => $request->user()->id,
            'action' => 'rate_card.updated',
            'entity_type' => 'rate_card',
            'entity_id' => $card->id,
        ]);

        return back();
    }
}
