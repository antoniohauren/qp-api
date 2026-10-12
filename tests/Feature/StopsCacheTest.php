<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;

use function Pest\Laravel\getJson;
use function Pest\Laravel\travelTo;

beforeEach(function () {
    config()->set([
        'cache.default' => 'array',
        'services.quero_passagem.url' => 'https://queropassagem.example',
        'services.quero_passagem.username' => 'user',
        'services.quero_passagem.password' => 'secret',
    ]);

    Http::preventStrayRequests();
});

test('it reuses the stops list until exactly one hour after fetching', function () {
    $startedAt = now()->startOfSecond();
    travelTo($startedAt);
    $stop = ['id' => 'CIT_1', 'name' => 'Curitiba, PR', 'type' => 'city'];
    $updatedStop = ['id' => 'CIT_2', 'name' => 'Rio de Janeiro, RJ', 'type' => 'city'];
    Http::fake([
        'https://queropassagem.example/ws_v4/stops' => Http::sequence()
            ->push([$stop])
            ->push([$updatedStop]),
    ]);

    getJson('/api/stops')->assertOk()->assertExactJson([[...$stop, 'available' => true]]);

    travelTo($startedAt->copy()->addSeconds(3599));
    getJson('/api/stops')->assertOk()->assertExactJson([[...$stop, 'available' => true]]);
    Http::assertSentCount(1);

    travelTo($startedAt->copy()->addHour());
    getJson('/api/stops')->assertOk()->assertExactJson([[...$updatedStop, 'available' => false]]);
    Http::assertSentCount(2);
});

test('it caches a successful empty stops list', function () {
    Http::fake([
        'https://queropassagem.example/ws_v4/stops' => Http::sequence()->push([]),
    ]);

    getJson('/api/stops')->assertOk()->assertExactJson([]);
    getJson('/api/stops')->assertOk()->assertExactJson([]);

    Http::assertSentCount(1);
});

test('it returns 502 without caching a provider failure', function () {
    $stop = ['name' => 'Curitiba, PR', 'type' => 'city'];
    Http::fake([
        'https://queropassagem.example/ws_v4/stops' => Http::sequence()
            ->push(['errors' => ['private provider details']], 503)
            ->push([$stop]),
    ]);

    getJson('/api/stops')->assertStatus(502)
        ->assertExactJson(['errors' => ['The travel provider is currently unavailable.']]);
    getJson('/api/stops')->assertOk()->assertExactJson([[...$stop, 'available' => true]]);
    getJson('/api/stops')->assertOk()->assertExactJson([[...$stop, 'available' => true]]);

    Http::assertSentCount(2);
});

test('it returns 502 without caching a failed connection', function () {
    Http::fake([
        'https://queropassagem.example/ws_v4/stops' => Http::sequence()
            ->pushFailedConnection('private connection details')
            ->push([]),
    ]);

    getJson('/api/stops')->assertStatus(502)
        ->assertExactJson(['errors' => ['The travel provider is currently unavailable.']]);
    getJson('/api/stops')->assertOk()->assertExactJson([]);
    getJson('/api/stops')->assertOk()->assertExactJson([]);

    Http::assertSentCount(2);
});

test('it isolates cached stops by provider configuration', function (string $setting, string $value) {
    $stop = ['name' => 'Curitiba, PR', 'type' => 'city'];
    $sequence = Http::sequence()->push([$stop])->push([]);
    Http::fake([
        'https://queropassagem.example/ws_v4/stops' => $sequence,
        'https://other-provider.example/ws_v4/stops' => $sequence,
    ]);

    getJson('/api/stops')->assertOk()->assertExactJson([[...$stop, 'available' => true]]);

    $original = config('services.quero_passagem.'.$setting);
    config()->set('services.quero_passagem.'.$setting, $value);
    getJson('/api/stops')->assertOk()->assertExactJson([]);

    config()->set('services.quero_passagem.'.$setting, $original);
    getJson('/api/stops')->assertOk()->assertExactJson([[...$stop, 'available' => true]]);

    Http::assertSentCount(2);
})->with([
    'provider URL' => ['url', 'https://other-provider.example'],
    'provider username' => ['username', 'other-user'],
    'provider password' => ['password', 'other-secret'],
]);
