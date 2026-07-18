<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** FR-24 / BR-01: kelola anggota (invite + role) & daftar klien (derivasi dari proyek). */
class TeamController extends Controller
{
    private function memberFor(Request $request, Workspace $workspace): ?WorkspaceMember
    {
        return $workspace->members()->where('user_id', $request->user()->id)->first();
    }

    /** Batas anggota: paket team → seats; selain itu config paket; tanpa langganan → free. */
    private function membersLimit(Workspace $workspace): ?int
    {
        $plan = $workspace->subscription?->plan ?? 'free';

        return $plan === 'team'
            ? $workspace->subscription?->seats
            : config("spekta.plans.$plan.members");
    }

    public function index(Request $request)
    {
        $workspace = $request->user()->currentWorkspace();
        $member = $this->memberFor($request, $workspace);

        $members = $workspace->members()->with('user')
            ->orderByRaw("CASE WHEN role = 'owner' THEN 0 ELSE 1 END")
            ->orderBy('created_at')
            ->get()
            ->map(fn (WorkspaceMember $m) => [
                'id' => $m->id,
                'user_id' => $m->user_id,
                'name' => $m->user?->name,
                'email' => $m->user?->email,
                'role' => $m->role,
                'hide_prices' => (bool) $m->hide_prices,
                'joined_at' => $m->created_at?->toDateString(),
            ]);

        // Klien diturunkan dari client_name proyek — belum ada tabel clients tersendiri
        $clients = $workspace->projects()
            ->whereNotNull('client_name')
            ->where('client_name', '!=', '')
            ->get()
            ->groupBy('client_name')
            ->map(function ($group, $name) {
                $last = $group->sortByDesc('updated_at')->first();

                return [
                    'name' => $name,
                    'projects_count' => $group->count(),
                    'last_project' => $last->name,
                    'last_status' => $last->status,
                    'last_activity' => $last->updated_at?->toDateString(),
                ];
            })
            ->sortByDesc('last_activity')
            ->values();

        return Inertia::render('team', [
            'members' => $members,
            'clients' => $clients,
            'canManage' => in_array($member?->role, ['owner', 'admin']),
            'currentUserId' => $request->user()->id,
            'limit' => [
                'members_limit' => $this->membersLimit($workspace),
                'members_used' => $workspace->members()->count(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $workspace = $request->user()->currentWorkspace();
        $actor = $this->memberFor($request, $workspace);
        abort_unless(in_array($actor?->role, ['owner', 'admin']), 403);

        // Normalisasi lowercase — register memvalidasi lowercase; tanpa ini email beda
        // kapitalisasi bikin akun duplikat (unique users.email case-sensitive di Postgres)
        $request->merge(['email' => strtolower((string) $request->input('email'))]);

        $data = $request->validate([
            'email' => 'required|email',
            'role' => 'required|in:admin,member',
        ]);

        $limit = $this->membersLimit($workspace);
        if ($limit !== null && $workspace->members()->count() >= $limit) {
            throw ValidationException::withMessages(['email' => 'Batas anggota paket tercapai — upgrade paket.']);
        }

        $user = User::where('email', $data['email'])->first();
        if (! $user) {
            $user = User::create([
                'name' => Str::before($data['email'], '@'),
                'email' => $data['email'],
                'password' => Hash::make(Str::random(32)),
            ]);
            // ponytail: undangan = reset-link, email undangan khusus belum
            Password::sendResetLink(['email' => $user->email]);
        }

        if ($workspace->members()->where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages(['email' => 'Pengguna sudah menjadi anggota workspace.']);
        }

        $workspace->members()->create([
            'user_id' => $user->id,
            'role' => $data['role'],
        ]);

        AuditLog::create([
            'workspace_id' => $workspace->id,
            'actor_id' => $request->user()->id,
            'action' => 'member.invited',
            'entity_type' => 'workspace_member',
            'entity_id' => $user->id,
        ]);

        return back();
    }

    public function update(Request $request, int $memberId)
    {
        $workspace = $request->user()->currentWorkspace();
        $actor = $this->memberFor($request, $workspace);
        abort_unless(in_array($actor?->role, ['owner', 'admin']), 403);

        $data = $request->validate([
            'role' => 'sometimes|in:owner,admin,member',
            'hide_prices' => 'sometimes|boolean',
        ]);

        $target = $workspace->members()->findOrFail($memberId);

        if (array_key_exists('role', $data) && $data['role'] !== $target->role) {
            // Hanya owner yang boleh memberi/mencabut peran owner
            if (($data['role'] === 'owner' || $target->role === 'owner') && $actor->role !== 'owner') {
                abort(403, 'Hanya owner yang dapat mengatur peran owner.');
            }
            // Tidak bisa menurunkan owner terakhir
            if ($target->role === 'owner' && $data['role'] !== 'owner') {
                abort_if($workspace->members()->where('role', 'owner')->count() <= 1, 422, 'Tidak bisa menurunkan owner terakhir.');
            }
            $target->role = $data['role'];

            AuditLog::create([
                'workspace_id' => $workspace->id,
                'actor_id' => $request->user()->id,
                'action' => 'member.role_updated',
                'entity_type' => 'workspace_member',
                'entity_id' => $target->id,
            ]);
        }

        if (array_key_exists('hide_prices', $data)) {
            $target->hide_prices = $request->boolean('hide_prices');
        }

        $target->save();

        return back();
    }

    public function destroy(Request $request, int $memberId)
    {
        $workspace = $request->user()->currentWorkspace();
        $actor = $this->memberFor($request, $workspace);
        abort_unless(in_array($actor?->role, ['owner', 'admin']), 403);

        $target = $workspace->members()->findOrFail($memberId);
        if ($target->role === 'owner') {
            abort_if($workspace->members()->where('role', 'owner')->count() <= 1, 422, 'Tidak bisa menghapus owner terakhir.');
        }

        $target->delete();

        AuditLog::create([
            'workspace_id' => $workspace->id,
            'actor_id' => $request->user()->id,
            'action' => 'member.removed',
            'entity_type' => 'workspace_member',
            'entity_id' => $memberId,
        ]);

        return back();
    }
}
