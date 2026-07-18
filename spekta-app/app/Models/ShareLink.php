<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ShareLink extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = [
        'contact_emails' => 'array',
        'doc_keys' => 'array',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function approvals()
    {
        return $this->hasMany(DocumentApproval::class);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    /** BR-27/BR-40: email boleh masuk portal — approver atau kontak terdaftar. */
    public function allowsEmail(string $email): bool
    {
        return strcasecmp($email, $this->approver_email) === 0
            || collect($this->contact_emails ?? [])->contains(fn ($e) => strcasecmp($e, $email) === 0);
    }
}
