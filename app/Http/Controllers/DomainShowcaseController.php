<?php

namespace App\Http\Controllers;

use App\Models\WorkspaceDomain;
use App\Services\Domains\ChannelPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The home page of a custom domain: the workspace's channel.
 * Unlisted and private items never appear here.
 */
class DomainShowcaseController extends Controller
{
    public function __construct(protected ChannelPresenter $channel) {}

    public function __invoke(Request $request, WorkspaceDomain $domain): Response
    {
        $page = $this->channel->page($domain->workspace, $request);
        $brand = $page['brand'];

        return Inertia::render('public/Showcase', $page)->withViewData([
            'seo' => [
                'title' => $brand['name'],
                'description' => Str::limit(__('Virtual tours and property videos by :name.', ['name' => $brand['name']]), 160),
                'image' => $brand['logo_url'],
                'url' => $domain->url(),
                'indexable' => true,
                'site_name' => $brand['site_name'],
            ],
        ]);
    }
}
