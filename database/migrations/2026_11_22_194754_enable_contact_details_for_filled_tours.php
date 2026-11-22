<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Contact details now have an on/off switch. Tours that already had some
     * filled in were showing them, so they start switched on.
     */
    public function up(): void
    {
        DB::table('virtual_tours')->whereNotNull('branding_settings')->orderBy('id')->each(function (object $tour): void {
            $branding = json_decode((string) $tour->branding_settings, true);
            $contact = is_array($branding) ? ($branding['contact'] ?? null) : null;

            if (! is_array($contact) || isset($contact['enabled']) || array_filter($contact) === []) {
                return;
            }

            $branding['contact']['enabled'] = true;

            DB::table('virtual_tours')->where('id', $tour->id)->update(['branding_settings' => json_encode($branding)]);
        });
    }

    public function down(): void
    {
        //
    }
};
