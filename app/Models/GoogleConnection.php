<?php

namespace App\Models;

use Database\Factories\GoogleConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A workspace's link to one Google account, used to publish to Street View.
 * Only the long-lived refresh token is kept, encrypted.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $connected_by
 * @property string|null $google_email
 * @property string $refresh_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Workspace $workspace
 */
#[Hidden(['refresh_token'])]
class GoogleConnection extends Model
{
    /** @use HasFactory<GoogleConnectionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['refresh_token' => 'encrypted'];
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
