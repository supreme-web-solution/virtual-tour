<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WorkspaceDomain;
use App\Services\Cloudflare\CloudflareCustomHostnames;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Every custom hostname Cloudflare currently holds, matched to the workspace
 * that connected it. Orphans (not linked to any workspace) can be deleted.
 */
class CloudflareHostnameController extends Controller
{
    public function __construct(protected CloudflareCustomHostnames $cloudflare) {}

    public function index(Request $request): Response
    {
        $enabled = $this->cloudflare->configured();
        $error = null;
        $rows = [];

        if ($enabled) {
            try {
                $rows = $this->cloudflare->list();
            } catch (Throwable $exception) {
                $error = $exception->getMessage();
            }
        }

        $linked = WorkspaceDomain::query()
            ->with('workspace.owner:id,name,email')
            ->whereIn('cloudflare_hostname_id', array_column($rows, 'id') ?: [''])
            ->get()
            ->keyBy('cloudflare_hostname_id');

        return Inertia::render('admin/CloudflareHostnames', [
            'enabled' => $enabled,
            'error' => $error,
            'hostnames' => array_map(function (array $row) use ($linked): array {
                $domain = $linked->get($row['id']);
                $owner = $domain?->workspace?->owner;

                return [
                    'id' => $row['id'],
                    'hostname' => $row['hostname'],
                    'status' => $row['status'],
                    'ssl_status' => $row['ssl_status'],
                    'errors' => $row['errors'],
                    'type' => $domain === null ? 'orphan' : 'tour_domain',
                    'workspace' => $domain === null ? null : [
                        'id' => $domain->workspace->ulid,
                        'name' => $domain->workspace->name,
                    ],
                    'account' => $owner === null ? null : ['name' => $owner->name, 'email' => $owner->email],
                    'local_host' => $domain?->host,
                ];
            }, $rows),
        ]);
    }

    /**
     * Delete the custom hostname on Cloudflare and clear it from the matching
     * workspace domain. The domain itself stays connected in the app.
     */
    public function destroy(string $hostnameId): RedirectResponse
    {
        abort_unless(preg_match('/^[a-f0-9-]{20,64}$/', $hostnameId) === 1, 404);

        try {
            $this->cloudflare->delete($hostnameId);
        } catch (Throwable $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Cloudflare refused to delete it: :reason', ['reason' => $exception->getMessage()])]);

            return back();
        }

        WorkspaceDomain::where('cloudflare_hostname_id', $hostnameId)->update([
            'cloudflare_hostname_id' => null,
            'cloudflare_status' => null,
            'cloudflare_ssl_status' => null,
            'cloudflare_ownership_txt_name' => null,
            'cloudflare_ownership_txt_value' => null,
            'cloudflare_errors' => null,
            'cloudflare_synced_at' => null,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Removed the Cloudflare custom hostname. The domain stays connected in the app.')]);

        return back();
    }
}
