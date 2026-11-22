<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Services\Domains\ChannelPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A workspace's public channel at /c/{slug}: a searchable gallery of its
 * public tours and videos. Only exists once the owner turns it on.
 */
class ChannelController extends Controller
{
    public function __construct(protected ChannelPresenter $channel) {}

    public function __invoke(Request $request, string $slug): Response
    {
        $workspace = Workspace::where('channel_slug', $slug)->where('channel_enabled', true)->firstOrFail();
        $page = $this->channel->page($workspace, $request);
        $brand = $page['brand'];

        return Inertia::render('public/Showcase', $page)->withViewData([
            'seo' => [
                'title' => $brand['name'],
                'description' => Str::limit(__('Virtual tours and property videos by :name.', ['name' => $brand['name']]), 160),
                'image' => $brand['logo_url'],
                'url' => route('channel.show', $slug),
                'indexable' => true,
                'site_name' => $brand['site_name'],
            ],
        ]);
    }
}
