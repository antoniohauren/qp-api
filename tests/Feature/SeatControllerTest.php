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

    postJson('/api/seats', ['travelId' => '1_c1e3517874534111585cb00901c25e8e'])
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

test('it preserves seat provider errors and status', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://queropassagem.example/ws_v4/new/seats' => Http::response([
            'errors' => ["Parameter 'travelId' is missing or invalid."],
        ], 422),
    ]);

    postJson('/api/seats', ['travelId' => 'invalid'])
        ->assertUnprocessable()
        ->assertExactJson([
            'errors' => ["Parameter 'travelId' is missing or invalid."],
        ]);

    Http::assertSentCount(1);
});

test('it rejects a missing travel ID with 422', function () {
    Http::preventStrayRequests();

    postJson('/api/seats')
        ->assertUnprocessable()
        ->assertInvalid([
            'travelId' => 'The travel id field is required.',
        ]);

    Http::assertNothingSent();
});
