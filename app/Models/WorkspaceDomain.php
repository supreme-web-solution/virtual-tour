<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $ulid
 * @property int $workspace_id
 * @property string $host
 * @property string $status pending|verified|failed
 * @property string $verification_token
 * @property Carbon|null $verified_at
 * @property Carbon|null $last_checked_at
 * @property string|null $last_error
 * @property string|null $cloudflare_hostname_id
 * @property string|null $cloudflare_status
 * @property string|null $cloudflare_ssl_status
 * @property string|null $cloudflare_ownership_txt_name
 * @property string|null $cloudflare_ownership_txt_value
 * @property list<string>|null $cloudflare_errors
 * @property Carbon|null $cloudflare_synced_at
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Workspace $workspace
 */
#[Fillable(['host', 'status', 'verification_token', 'verified_at', 'last_checked_at', 'last_error'])]
class WorkspaceDomain extends Model
{
    use HasPublicUlid;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'cloudflare_errors' => 'array',
            'cloudflare_synced_at' => 'datetime',
        ];
    }

    public static function newToken(): string
    {
        return 'tour-verify='.Str::lower(Str::random(32));
    }

    public function isVerified(): bool
    {
        return $this->status === 'verified';
    }

    public function txtRecordName(): string
    {
        return config('domains.txt_prefix', '_tour-verify').'.'.$this->host;
    }

    public function url(): string
    {
        return 'https://'.$this->host;
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
