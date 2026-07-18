<?php

namespace App\Http\Middleware;

use App\Services\WorkspaceProvisioner;
use Closure;
use Illuminate\Http\Request;

/**
 * User tanpa workspace (provision gagal, dikeluarkan dari satu-satunya workspace,
 * dibuat via factory/OAuth) → provision otomatis. Controller ber-auth boleh
 * mengasumsikan currentWorkspace() tidak null.
 */
class EnsureWorkspace
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if ($user && ! $user->currentWorkspace()) {
            $workspace = app(WorkspaceProvisioner::class)->provision($user, $user->name);
            $user->current_workspace_id = $workspace->id;
            $user->save();
        }

        return $next($request);
    }
}
