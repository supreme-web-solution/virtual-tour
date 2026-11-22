<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use Carbon\CarbonInterface;
use Database\Factories\TourReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A visitor's report of a public tour for inappropriate or illegal content.
 *
 * @property int $id
 * @property string $ulid
 * @property int $virtual_tour_id
 * @property string $reason
 * @property string|null $details
 * @property string|null $reporter_hash
 * @property CarbonInterface|null $resolved_at
 * @property CarbonInterface|null $created_at
 */
#[Fillable(['reason', 'details', 'reporter_hash', 'resolved_at'])]
class TourReport extends Model
{
    /** @use HasFactory<TourReportFactory> */
    use HasFactory, HasPublicUlid;

    /**
     * Reasons a visitor can choose, with the label shown in the form.
     *
     * @var array<string, string>
     */
    public const REASONS = [
        'illegal' => 'Illegal content',
        'sexual' => 'Sexually explicit or nude content',
        'gambling' => 'Gambling content',
        'violence' => 'Violence or terrorism',
        'harassment' => 'Harassment or hate speech',
        'copyright' => 'Copyright infringement',
        'other' => 'Other',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<VirtualTour, $this>
     */
    public function tour(): BelongsTo
    {
        return $this->belongsTo(VirtualTour::class, 'virtual_tour_id');
    }
}
