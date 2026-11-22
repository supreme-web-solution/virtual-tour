<?php

use App\Models\MediaAsset;
use App\Models\Plan;
use App\Models\Property;
use App\Models\Subscription;
use App\Models\TourLead;
use App\Models\TourScene;
use App\Models\User;
use App\Models\VirtualTour;
use App\Services\Billing\EntitlementService;

function apiPlan(bool $api = true): void
{
    Subscription::query()->where('workspace_id', test()->workspace->id)->delete();
    $plan = Plan::factory()->with(['api_access' => $api])->create();
    Subscription::factory()->create(['workspace_id' => test()->workspace->id, 'plan_id' => $plan->id]);
    app(EntitlementService::class)->forget();
}

function apiToken(?User $user = null, ?int $workspaceId = null): string
{
    $user ??= test()->owner;

    return $user->createToken('test', ['read', 'ws:'.($workspaceId ?? test()->workspace->id)])->plainTextToken;
}

function apiGet(string $uri, string $token)
{
    return test()->withToken($token)->getJson($uri);
}

beforeEach(function () {
    $this->owner = User::factory()->withWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace;
    apiPlan();
});

test('requests without a token are refused', function () {
    $this->getJson('/api/v1/workspace')->assertUnauthorized();
});

test('a token reads its own workspace', function () {
    apiGet('/api/v1/workspace', apiToken())
        ->assertOk()
        ->assertJsonPath('data.name', $this->workspace->name)
        ->assertJsonStructure(['data' => ['credits_available']]);
});

test('lists only show the token workspace data', function () {
    Property::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Mine']);
    Property::factory()->create(['workspace_id' => User::factory()->withWorkspace()->create()->currentWorkspace->id, 'name' => 'Theirs']);
    MediaAsset::factory()->create(['workspace_id' => $this->workspace->id]);

    apiGet('/api/v1/properties', apiToken())
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Mine')
        ->assertJsonMissingPath('data.0.workspace_id');

    apiGet('/api/v1/media', apiToken())->assertOk()->assertJsonCount(1, 'data');
});

test('a tour is returned with its scenes, and others tours are not found', function () {
    $tour = VirtualTour::factory()->inWorkspace($this->workspace)->published()->create(['title' => 'Villa']);
    TourScene::factory()->forTour($tour)->create();
    $other = VirtualTour::factory()->inWorkspace(User::factory()->withWorkspace()->create()->currentWorkspace)->create();

    apiGet("/api/v1/tours/{$tour->ulid}", apiToken())
        ->assertOk()
        ->assertJsonPath('data.title', 'Villa')
        ->assertJsonCount(1, 'data.scenes')
        ->assertJsonPath('data.url', fn ($url) => str_contains($url, $tour->public_id));

    apiGet("/api/v1/tours/{$other->ulid}", apiToken())->assertNotFound();
});

test('leads are listed with their tour', function () {
    $tour = VirtualTour::factory()->inWorkspace($this->workspace)->create();
    $lead = new TourLead(['name' => 'Ada', 'email' => 'ada@example.com']);
    $lead->workspace_id = $this->workspace->id;
    $lead->virtual_tour_id = $tour->id;
    $lead->save();

    apiGet('/api/v1/leads', apiToken())
        ->assertOk()
        ->assertJsonPath('data.0.email', 'ada@example.com')
        ->assertJsonPath('data.0.tour_id', $tour->ulid);
});

test('the plan must include API access', function () {
    apiPlan(false);

    apiGet('/api/v1/workspace', apiToken())->assertForbidden();
});

test('a token stops working once its user leaves the workspace', function () {
    $stranger = User::factory()->withWorkspace()->create();

    apiGet('/api/v1/workspace', apiToken($stranger, $this->workspace->id))->assertForbidden();
});

test('creating a token needs the plan feature and shows it once', function () {
    $this->actingAs($this->owner)
        ->post(route('api-tokens.store'), ['name' => 'Website'])
        ->assertRedirect(route('api-tokens.index'))
        ->assertSessionHas('api_token');

    expect($this->owner->tokens()->sole()->abilities)->toBe(['read', 'ws:'.$this->workspace->id]);

    apiPlan(false);

    $this->actingAs($this->owner)->post(route('api-tokens.store'), ['name' => 'Second'])->assertForbidden();
});

test('a token can be deleted by its owner only', function () {
    $token = $this->owner->createToken('x', ['read', 'ws:'.$this->workspace->id])->accessToken;
    $other = User::factory()->withWorkspace()->create();

    $this->actingAs($other)->delete(route('api-tokens.destroy', $token->id))->assertNotFound();
    $this->actingAs($this->owner)->delete(route('api-tokens.destroy', $token->id))->assertRedirect();

    expect($this->owner->tokens()->count())->toBe(0);
});

test('the MCP endpoint lists tools and runs one', function () {
    Property::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Loft']);
    $token = apiToken();

    $this->withToken($token)->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'])
        ->assertOk()->assertJsonPath('result.serverInfo.version', '1.0.0');

    $this->withToken($token)->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'])
        ->assertOk()->assertJsonPath('result.tools.0.name', 'get_workspace');

    $response = $this->withToken($token)->postJson('/api/mcp', [
        'jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call',
        'params' => ['name' => 'list_properties', 'arguments' => ['search' => 'Loft']],
    ])->assertOk();

    expect($response->json('result.content.0.text'))->toContain('Loft');

    $this->withToken($token)->postJson('/api/mcp', ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'])->assertStatus(202);
    $this->withToken($token)->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'nope'])->assertJsonPath('error.code', -32601);
});
