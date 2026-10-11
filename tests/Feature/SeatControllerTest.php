<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\postJson;

beforeEach(function () {
    config()->set([
        'services.quero_passagem.url' => 'https://queropassagem.example',
        'services.quero_passagem.username' => 'user',
        'services.quero_passagem.password' => 'secret',
    ]);
});

test('it returns seats for a travel ID', function () {
    $seats = [[
        'orientation' => 'horizontal',
        'floor' => 0,
        'seatsLayout' => 'numbered',
        'seats' => [[[
            'seat' => '03',
            'position' => ['x' => 1, 'y' => 4, 'z' => 0],
            'occupied' => true,
            'type' => 'seat',
        ]]],
    ]];
    Http::preventStrayRequests();
    Http::fake([
        'https://queropassagem.example/ws_v4/new/seats' => Http::response($seats),
    ]);

    postJson('/api/seats', [
        'travelId' => '1_c1e3517874534111585cb00901c25e8e',
        'orientation' => 'vertical',
        'type' => 'list',
    ])
        ->assertOk()
        ->assertExactJson($seats);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://queropassagem.example/ws_v4/new/seats'
        && $request->method() === 'POST'
        && $request->hasHeader('Accept', 'application/json')
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('user:secret'))
        && $request->data() === [
            'travelId' => '1_c1e3517874534111585cb00901c25e8e',
            'orientation' => 'horizontal',
            'type' => 'matrix',
        ]);
});

test('it returns controlled errors when the seat provider fails', function (int $providerStatus, int $publicStatus) {
    Http::preventStrayRequests();
    Http::fake([
        'https://queropassagem.example/ws_v4/new/seats' => Http::response([
            'errors' => ["Parameter 'travelId' is missing or invalid."],
        ], $providerStatus),
    ]);

    postJson('/api/seats', ['travelId' => 'invalid'])
        ->assertStatus($publicStatus)
        ->assertExactJson([
            'errors' => ['The travel provider is currently unavailable.'],
        ]);

    Http::assertSentCount(1);
})->with([
    'invalid parameter' => [422, 502],
    'not found' => [404, 502],
    'rate limited' => [429, 503],
    'server error' => [500, 502],
]);

test('it rejects a missing travel ID with 422', function () {
    Http::preventStrayRequests();

    postJson('/api/seats')
        ->assertUnprocessable()
        ->assertInvalid([
            'travelId' => 'The travel id field is required.',
        ]);

    Http::assertNothingSent();
});

test('it rejects an invalid travel ID with 422', function (mixed $travelId, string $message) {
    Http::preventStrayRequests();

    postJson('/api/seats', ['travelId' => $travelId])
        ->assertUnprocessable()
        ->assertInvalid(['travelId' => $message]);

    Http::assertNothingSent();
})->with([
    'empty' => ['', 'The travel id field is required.'],
    'integer' => [123, 'The travel id field must be a string.'],
    'array' => [['123'], 'The travel id field must be a string.'],
    'too long' => [str_repeat('a', 256), 'The travel id field must not be greater than 255 characters.'],
]);

test('it accepts a travel ID of 255 characters', function () {
    $travelId = str_repeat('a', 255);
    Http::preventStrayRequests();
    Http::fake([
        'https://queropassagem.example/ws_v4/new/seats' => Http::response([]),
    ]);

    postJson('/api/seats', ['travelId' => $travelId])
        ->assertOk()
        ->assertExactJson([]);

    Http::assertSent(fn (Request $request): bool => $request['travelId'] === $travelId);
});
