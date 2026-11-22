<?php

use App\Contracts\AI\StructuredOutputProvider;
use App\Contracts\Media\MediaStorage;
use App\Enums\AssetSource;
use App\Enums\WorkspaceRole;
use App\Exceptions\ImportException;
use App\Exceptions\ProviderException;
use App\Models\MediaAsset;
use App\Models\Property;
use App\Models\PropertyImport;
use App\Models\User;
use App\Services\Credits\CreditService;
use App\Services\Import\HostResolver;
use App\Services\Import\ListingParser;
use App\Services\Import\RobotsPolicy;
use App\Services\Import\SafeHttpClient;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\FakeHostResolver;
use Tests\Fakes\FakeMediaStorage;
use Tests\Fakes\FakeStructuredOutputProvider;

beforeEach(function () {
    config(['ai.operations.listing_extraction.credits' => 1]);

    $this->resolver = new FakeHostResolver;
    $this->app->instance(HostResolver::class, $this->resolver);
    $this->storage = new FakeMediaStorage;
    $this->app->instance(MediaStorage::class, $this->storage);
    $this->ai = new FakeStructuredOutputProvider([
        'name' => 'Lekki Garden Villa',
        'property_type' => 'villa',
        'description' => 'Four-bedroom villa with a pool.',
        'address' => null,
        'city' => 'Lagos',
        'region' => 'Lagos State',
        'country' => 'Nigeria',
        'bedrooms' => 4,
        'bathrooms' => 4.5,
        'area' => 450,
        'area_unit' => 'sqm',
        'price' => 250000000,
        'currency' => 'NGN',
    ]);
    $this->app->instance(StructuredOutputProvider::class, $this->ai);

    $this->owner = User::factory()->withWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace;
    $this->credits = app(CreditService::class);
    $this->credits->grant($this->workspace->id, 10, 'Test');
});

/**
 * A real JPEG of the given size, so image validation runs for real.
 */
function jpegBytes(int $width = 1200, int $height = 800): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 120, 160, 200));
    ob_start();
    imagejpeg($image, null, 80);

    return (string) ob_get_clean();
}

function listingHtml(): string
{
    return <<<'HTML'
    <html><head>
        <title>Lekki Garden Villa | Example Homes</title>
        <meta property="og:image" content="https://cdn.listings.test/photos/front.jpg">
        <script type="application/ld+json">{"@type":"SingleFamilyResidence","name":"Lekki Garden Villa","numberOfBedrooms":4,
            "address":{"addressLocality":"Lagos","addressCountry":"NG"},"image":["/photos/living.jpg"]}</script>
    </head><body>
        <header><img src="/assets/logo.png"></header>
        <main><h1>Lekki Garden Villa</h1><p>Four bedrooms, a pool and a garden in Lekki Phase 1. Price NGN 250,000,000.</p>
            <img src="/photos/kitchen.jpg" alt="Kitchen">
            <img data-src="/photos/pool.webp" alt="Pool">
            <img src="/icons/bed.svg">
        </main>
    </body></html>
    HTML;
}

function fakeListingSite(array $overrides = []): void
{
    Http::fake([
        'https://listings.test/robots.txt' => fn () => Http::response("User-agent: *\nDisallow: /private/", 200, ['Content-Type' => 'text/plain']),
        'https://cdn.listings.test/robots.txt' => fn () => Http::response('', 404),
        'https://listings.test/homes/lekki' => fn () => Http::response(listingHtml(), 200, ['Content-Type' => 'text/html; charset=utf-8']),
        'https://listings.test/photos/*' => fn () => Http::response(jpegBytes(), 200, ['Content-Type' => 'image/jpeg']),
        'https://cdn.listings.test/photos/*' => fn () => Http::response(jpegBytes(300, 200), 200, ['Content-Type' => 'image/jpeg']),
        ...$overrides,
    ]);
}

test('the client refuses addresses that lead to private networks', function (string $url) {
    $this->resolver->records['intranet.example.com'] = ['10.0.0.5'];
    $this->resolver->records['mixed.example.com'] = ['93.184.215.14', '127.0.0.1'];

    expect(fn () => app(SafeHttpClient::class)->inspect($url))->toThrow(ImportException::class);
})->with([
    'localhost' => 'http://localhost/admin',
    'loopback ip' => 'http://127.0.0.1/',
    'metadata service' => 'http://169.254.169.254/latest/meta-data/',
    'private ip' => 'https://192.168.1.10/',
    'carrier nat' => 'https://100.64.0.1/',
    'ipv6 loopback' => 'http://[::1]/',
    'ipv4-mapped ipv6' => 'http://[::ffff:127.0.0.1]/',
    'private dns' => 'https://intranet.example.com/',
    'one private record' => 'https://mixed.example.com/',
    'odd port' => 'https://listings.test:8080/',
    'credentials' => 'https://user:pass@listings.test/',
    'file scheme' => 'file:///etc/passwd',
    'internal tld' => 'https://db.internal/',
]);

test('redirects are checked hop by hop', function () {
    $this->resolver->records['evil.test'] = ['10.1.2.3'];
    Http::fake([
        'https://listings.test/go' => Http::response('', 302, ['Location' => 'https://evil.test/secret']),
    ]);

    expect(fn () => app(SafeHttpClient::class)->fetch('https://listings.test/go', ['text/html'], 1000))
        ->toThrow(ImportException::class, 'private network');
});

test('downloads are limited in size and content type', function () {
    Http::fake([
        'https://listings.test/big' => Http::response(str_repeat('a', 5000), 200, ['Content-Type' => 'text/html']),
        'https://listings.test/pdf' => Http::response('%PDF', 200, ['Content-Type' => 'application/pdf']),
        'https://listings.test/blocked' => Http::response('no', 403, ['Content-Type' => 'text/html']),
    ]);
    $client = app(SafeHttpClient::class);

    expect(fn () => $client->fetch('https://listings.test/big', ['text/html'], 1000))->toThrow(ImportException::class, 'larger than')
        ->and(fn () => $client->fetch('https://listings.test/pdf', ['text/html'], 1000))->toThrow(ImportException::class, 'content type')
        ->and(fn () => $client->fetch('https://listings.test/blocked', ['text/html'], 1000))->toThrow(ImportException::class, 'does not allow automated access');
});

test('robots.txt rules are respected, most specific rule first', function () {
    $robots = app(RobotsPolicy::class);
    $rules = $robots->parse("User-agent: *\nDisallow: /\n\nUser-agent: PropertyImporter\nDisallow: /search\nAllow: /search/public\nDisallow: /*.pdf$");

    expect($rules)->toHaveCount(3);

    Http::fake(['https://listings.test/robots.txt' => Http::response("User-agent: PropertyImporter\nDisallow: /search\nAllow: /search/public\n", 200, ['Content-Type' => 'text/plain'])]);

    expect($robots->allows('https://listings.test/homes/1'))->toBeTrue()
        ->and($robots->allows('https://listings.test/search?q=villa'))->toBeFalse()
        ->and($robots->allows('https://listings.test/search/public/1'))->toBeTrue();
});

test('the parser finds listing facts and photos but skips icons and logos', function () {
    $page = app(ListingParser::class)->parse(listingHtml(), 'https://listings.test/homes/lekki');

    expect($page['title'])->toBe('Lekki Garden Villa')
        ->and($page['structured']['bedrooms'])->toBe(4)
        ->and($page['structured']['city'])->toBe('Lagos')
        ->and(array_column($page['images'], 'url'))->toBe([
            'https://cdn.listings.test/photos/front.jpg',
            'https://listings.test/photos/living.jpg',
            'https://listings.test/photos/kitchen.jpg',
            'https://listings.test/photos/pool.webp',
        ])
        ->and($page['text'])->toContain('Four bedrooms')
        ->and($page['is_challenge'])->toBeFalse();
});

test('importing a listing reads the page with AI, then creates the property and copies the chosen photos', function () {
    fakeListingSite();

    $this->actingAs($this->owner)
        ->postJson(route('imports.store'), ['url' => 'https://listings.test/homes/lekki'])
        ->assertCreated()
        ->assertJsonPath('import.status', 'ready');

    $import = PropertyImport::sole();
    expect($import->status)->toBe('ready')
        ->and($import->details['name'])->toBe('Lekki Garden Villa')
        ->and($import->details['bedrooms'])->toBe(4)
        ->and($import->details['price'])->toEqual(250000000)
        ->and($import->images)->toHaveCount(4)
        ->and($this->credits->wallet($this->workspace->id)->balance)->toBe(9);

    $this->getJson(route('imports.show', $import))
        ->assertOk()
        ->assertJsonPath('import.status', 'ready')
        ->assertJsonCount(4, 'import.images');

    $this->post(route('imports.complete', $import), [
        'target' => 'new',
        'details' => [...$import->details, 'name' => 'Lekki Villa (edited)'],
        'image_urls' => ['https://listings.test/photos/kitchen.jpg', 'https://listings.test/photos/living.jpg', 'https://cdn.listings.test/photos/front.jpg'],
        'rights_confirmed' => true,
    ])->assertOk()->assertJsonPath('import.status', 'completed');

    $import->refresh();
    $property = Property::sole();
    $assets = MediaAsset::where('property_id', $property->id)->get();

    expect($property->name)->toBe('Lekki Villa (edited)')
        ->and($property->metadata['source']['url'])->toBe('https://listings.test/homes/lekki')
        ->and($import->status)->toBe('completed')
        ->and($import->imported_count)->toBe(2)
        ->and($import->failed_count)->toBe(1)
        ->and($import->error)->toContain('too small')
        ->and($assets)->toHaveCount(2)
        ->and($assets->first()->source_type)->toBe(AssetSource::Imported)
        ->and($assets->first()->metadata['import']['page'])->toBe('https://listings.test/homes/lekki')
        ->and($property->cover_asset_id)->not->toBeNull();
});

test('photos are only copied after the user confirms they may use them', function () {
    $import = PropertyImport::factory()->ready()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->owner)
        ->post(route('imports.complete', $import), [
            'target' => 'new',
            'details' => $import->details,
            'image_urls' => ['https://cdn.example.com/photos/living.jpg'],
        ])
        ->assertSessionHasErrors('rights_confirmed');

    $this->post(route('imports.complete', $import), [
        'target' => 'new',
        'details' => $import->details,
        'image_urls' => ['https://attacker.test/other.jpg'],
        'rights_confirmed' => true,
    ])->assertSessionHasErrors('image_urls.0');

    expect(Property::count())->toBe(0);
});

test('sites that refuse automated access fail the import and return the credit', function (array $site, string $message) {
    fakeListingSite($site);

    $this->actingAs($this->owner)->post(route('imports.store'), ['url' => 'https://listings.test/homes/lekki']);

    $import = PropertyImport::sole();
    expect($import->status)->toBe('failed')
        ->and($import->error)->toContain($message)
        ->and($this->credits->available($this->workspace->id))->toBe(10);
})->with([
    'robots.txt' => [['https://listings.test/robots.txt' => fn () => Http::response("User-agent: *\nDisallow: /homes/", 200, ['Content-Type' => 'text/plain'])], 'robots.txt'],
    'forbidden' => [['https://listings.test/homes/lekki' => fn () => Http::response('Forbidden', 403, ['Content-Type' => 'text/html'])], 'does not allow automated access'],
    'bot check' => [['https://listings.test/homes/lekki' => fn () => Http::response('<html><body><div class="g-recaptcha"></div></body></html>', 200, ['Content-Type' => 'text/html'])], 'bot check'],
]);

test('an AI failure still imports the page metadata and releases the credit', function () {
    $this->app->instance(StructuredOutputProvider::class, new FakeStructuredOutputProvider(new ProviderException('Model overloaded')));
    fakeListingSite();

    $this->actingAs($this->owner)->post(route('imports.store'), ['url' => 'https://listings.test/homes/lekki']);

    $import = PropertyImport::sole();
    expect($import->status)->toBe('ready')
        ->and($import->details['name'])->toBe('Lekki Garden Villa')
        ->and($import->details['bedrooms'])->toBe(4)
        ->and($this->credits->available($this->workspace->id))->toBe(10);
});

test('private addresses are rejected before anything is queued', function () {
    $this->actingAs($this->owner)
        ->postJson(route('imports.store'), ['url' => 'http://127.0.0.1/admin'])
        ->assertJsonValidationErrors('url');

    expect(PropertyImport::count())->toBe(0);
});

test('viewers cannot import, and imports stay inside their workspace', function () {
    $import = PropertyImport::factory()->ready()->create(['workspace_id' => $this->workspace->id]);

    $viewer = User::factory()->create();
    $this->workspace->addMember($viewer, WorkspaceRole::Viewer);
    $viewer->switchWorkspace($this->workspace);

    $this->actingAs($viewer)->post(route('imports.store'), ['url' => 'https://listings.test/homes/lekki'])->assertForbidden();
    $this->actingAs($viewer)->post(route('imports.complete', $import), ['target' => 'new', 'details' => $import->details, 'image_urls' => []])->assertForbidden();

    $outsider = User::factory()->withWorkspace()->create();
    $this->actingAs($outsider)->get(route('imports.show', $import))->assertNotFound();
});
