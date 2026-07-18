<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    public function workspaces()
    {
        return $this->belongsToMany(Workspace::class, 'workspace_members')->withPivot('role', 'hide_prices');
    }

    public function currentWorkspace(): ?Workspace
    {
        // Pointer eksplisit hanya berlaku selama masih member (stale setelah dikeluarkan dari workspace)
        if ($this->current_workspace_id) {
            $chosen = $this->workspaces()->whereKey($this->current_workspace_id)->first();
            if ($chosen) {
                return $chosen;
            }
        }

        // Fallback deterministik: membership tertua
        return $this->workspaces()
            ->orderBy('workspace_members.created_at')
            ->orderBy('workspace_members.id')
            ->first();
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
