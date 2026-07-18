<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/** Ganti workspace aktif — hanya workspace di mana user terdaftar sebagai member. */
class WorkspaceController extends Controller
{
    public function switch(Request $request)
    {
        $data = $request->validate(['workspace_id' => 'required|uuid']);

        $user = $request->user();
        abort_unless($user->workspaces()->whereKey($data['workspace_id'])->exists(), 403, 'Bukan anggota workspace ini.');

        $user->current_workspace_id = $data['workspace_id'];
        $user->save();

        return redirect()->route('dashboard');
    }
}
