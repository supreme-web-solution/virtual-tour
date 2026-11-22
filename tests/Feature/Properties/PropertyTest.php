<?php

use App\Enums\PropertyStatus;
use App\Enums\WorkspaceRole;
use App\Models\MediaAsset;
use App\Models\Property;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A user whose current workspace is $owner's, with the given role.
 */
function memberOf(User $owner, WorkspaceRole $role): User
{
    $member = User::factory()->create();
    $owner->currentWorkspace->addMember($member, $role);
    $member->switchWorkspace($owner->currentWorkspace);

    return $member;
}

function validPropertyPayload(array $overrides = []): array
{
    return [
        'name' => 'Lekki Garden Villa',
        'property_type' => 'villa',
        'status' => 'active',
        'area_unit' => 'sqm',
        'city' => 'Lagos',
        'country' => 'Nigeria',
        'bedrooms' => 4,
        'price' => 250000000,
        'currency' => 'ngn',
        ...$overrides,
    ];
}

beforeEach(function () {
    $this->owner = User::factory()->withWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace;
});

test('the index lists only properties in the current workspace', function () {
    Property::factory()->inWorkspace($this->workspace)->create(['name' => 'Mine']);
    Property::factory()->create(['name' => 'Someone else']);

    $this->actingAs($this->owner)
        ->get(route('properties.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('properties/Index')
            ->has('properties.data', 1)
            ->where('properties.data.0.name', 'Mine'));
});

test('the index can be searched and filtered', function () {
    Property::factory()->inWorkspace($this->workspace)->create(['name' => 'Ikoyi Loft', 'status' => PropertyStatus::Active]);
    Property::factory()->inWorkspace($this->workspace)->create(['name' => 'Abuja Office', 'status' => PropertyStatus::Draft]);

    $this->actingAs($this->owner)
        ->get(route('properties.index', ['search' => 'ikoyi', 'status' => 'active']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('properties.data', 1)
            ->where('properties.data.0.name', 'Ikoyi Loft'));
});

test('an editor can create a property in their current workspace', function () {
    $editor = memberOf($this->owner, WorkspaceRole::Editor);

    $response = $this->actingAs($editor)->post(route('properties.store'), validPropertyPayload());

    $property = Property::firstOrFail();
    $response->assertRedirect(route('properties.show', $property));

    expect($property)
        ->workspace_id->toBe($this->workspace->id)
        ->created_by->toBe($editor->id)
        ->slug->toBe('lekki-garden-villa')
        ->currency->toBe('NGN');
});

test('slugs stay unique within a workspace', function () {
    $this->actingAs($this->owner);
    $this->post(route('properties.store'), validPropertyPayload());
    $this->post(route('properties.store'), validPropertyPayload());

    expect(Property::pluck('slug')->all())->toBe(['lekki-garden-villa', 'lekki-garden-villa-2']);
});

test('creating a property validates its input', function () {
    $this->actingAs($this->owner)
        ->post(route('properties.store'), validPropertyPayload([
            'name' => '',
            'property_type' => 'castle',
            'latitude' => 120,
            'price' => 100,
            'currency' => '',
        ]))
        ->assertSessionHasErrors(['name', 'property_type', 'latitude', 'longitude', 'currency']);

    expect(Property::count())->toBe(0);
});

test('a viewer cannot create or edit properties', function () {
    $viewer = memberOf($this->owner, WorkspaceRole::Viewer);
    $property = Property::factory()->inWorkspace($this->workspace)->create();

    $this->actingAs($viewer)->get(route('properties.show', $property))->assertOk();
    $this->actingAs($viewer)->get(route('properties.create'))->assertForbidden();
    $this->actingAs($viewer)->post(route('properties.store'), validPropertyPayload())->assertForbidden();
    $this->actingAs($viewer)->put(route('properties.update', $property), validPropertyPayload())->assertForbidden();
});

test('users cannot see or change another workspace\'s properties', function () {
    $outsider = User::factory()->withWorkspace()->create();
    $property = Property::factory()->inWorkspace($this->workspace)->create();

    $this->actingAs($outsider)->get(route('properties.show', $property))->assertForbidden();
    $this->actingAs($outsider)->put(route('properties.update', $property), validPropertyPayload())->assertForbidden();
    $this->actingAs($outsider)->delete(route('properties.destroy', $property))->assertForbidden();
});

test('updating a property renames its slug', function () {
    $property = Property::factory()->inWorkspace($this->workspace)->create(['name' => 'Old name']);

    $this->actingAs($this->owner)
        ->put(route('properties.update', $property), validPropertyPayload(['name' => 'New name']))
        ->assertRedirect(route('properties.show', $property));

    expect($property->fresh())->name->toBe('New name')->slug->toBe('new-name');
});

test('the cover image must be an image from the same workspace', function () {
    $property = Property::factory()->inWorkspace($this->workspace)->create();
    $foreign = MediaAsset::factory()->create();
    $own = MediaAsset::factory()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->owner)
        ->put(route('properties.update', $property), validPropertyPayload(['cover_asset_id' => $foreign->ulid]))
        ->assertSessionHasErrors('cover_asset_id');

    $this->actingAs($this->owner)
        ->put(route('properties.update', $property), validPropertyPayload(['cover_asset_id' => $own->ulid]))
        ->assertSessionHasNoErrors();

    expect($property->fresh()->cover_asset_id)->toBe($own->id);
});

test('duplicating copies the details into a new draft', function () {
    $property = Property::factory()->inWorkspace($this->workspace)->create([
        'name' => 'Seaside Villa',
        'status' => PropertyStatus::Active,
        'bedrooms' => 5,
    ]);

    $this->actingAs($this->owner)->post(route('properties.duplicate', $property));

    $copy = Property::whereKeyNot($property->id)->firstOrFail();

    expect($copy)
        ->name->toBe('Seaside Villa (copy)')
        ->status->toBe(PropertyStatus::Draft)
        ->bedrooms->toBe(5)
        ->slug->toBe('seaside-villa-copy')
        ->ulid->not->toBe($property->ulid);
});

test('deleting a property keeps its media in the library, unassigned', function () {
    $property = Property::factory()->inWorkspace($this->workspace)->create();
    $asset = MediaAsset::factory()->create(['workspace_id' => $this->workspace->id, 'property_id' => $property->id]);

    $this->actingAs($this->owner)
        ->delete(route('properties.destroy', $property))
        ->assertRedirect(route('properties.index'));

    expect($property->fresh()->trashed())->toBeTrue()
        ->and($asset->fresh()->property_id)->toBeNull();
});

test('editors can delete only the properties they created', function () {
    $editor = memberOf($this->owner, WorkspaceRole::Editor);
    $theirs = Property::factory()->inWorkspace($this->workspace, $editor->id)->create();
    $ownersProperty = Property::factory()->inWorkspace($this->workspace)->create();

    $this->actingAs($editor)->delete(route('properties.destroy', $ownersProperty))->assertForbidden();
    $this->actingAs($editor)->delete(route('properties.destroy', $theirs))->assertRedirect();

    expect($theirs->fresh()->trashed())->toBeTrue();
});
