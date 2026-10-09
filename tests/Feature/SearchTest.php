<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\getJson;

beforeEach(function () {
    config()->set([
        'services.quero_passagem.url' => 'https://queropassagem.example',
        'services.quero_passagem.username' => 'user',
        'services.quero_passagem.password' => 'secret',
        'services.quero_passagem.affiliate_code' => 'DDE',
    ]);

    Http::preventStrayRequests();
});

test('it searches trips between two available stops', function () {
    $trips = [
        [
            'id' => 'trip-next-day',
            'departure' => ['date' => '2026-09-16', 'time' => '01:00:00'],
        ],
        [
            'id' => 'trip-later',
            'departure' => ['date' => '2026-09-15', 'time' => '18:00:00'],
        ],
        [
            'id' => 'trip-earlier',
            'departure' => ['date' => '2026-09-15', 'time' => '08:00:00'],
        ],
    ];

    Http::fake([
        'https://queropassagem.example/ws_v4/stops/CIT_1' => Http::response([
            'id' => 'CIT_1',
            'name' => 'Campinas',
            'state' => 'SP',
            'type' => 'city',
        ]),
        'https://queropassagem.example/ws_v4/stops/CIT_2' => Http::response([
            'id' => 'CIT_2',
            'name' => 'Curitiba',
            'state' => 'PR',
            'type' => 'city',
        ]),
        'https://queropassagem.example/ws_v4/new/search/CIT_1/CIT_2/2026-09-15/DDE' => Http::response($trips),
    ]);

    getJson('/api/search/CIT_1/CIT_2/2026-09-15')
        ->assertOk()
        ->assertExactJson([
            $trips[2],
            $trips[1],
            $trips[0],
        ]);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://queropassagem.example/ws_v4/new/search/CIT_1/CIT_2/2026-09-15/DDE'
        && $request->hasHeader('Accept', 'application/json')
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('user:secret')));
});

test('it rejects identical origin and destination stops', function () {
    getJson('/api/search/CIT_1/CIT_1/2026-09-15')
        ->assertStatus(422)
        ->assertExactJson([
            'errors' => ["Parameters 'from' and 'to' must be different."],
        ]);

    Http::assertNothingSent();
});

test('it rejects unavailable stops', function () {
    Http::fake([
        'https://queropassagem.example/ws_v4/stops/CIT_1' => Http::response([
            'id' => 'CIT_1',
            'name' => 'Campinas',
            'state' => 'SP',
            'type' => 'city',
        ]),
        'https://queropassagem.example/ws_v4/stops/CIT_2' => Http::response([
            'id' => 'CIT_2',
            'name' => 'Rio de Janeiro',
            'state' => 'RJ',
            'type' => 'city',
        ]),
    ]);

    getJson('/api/search/CIT_1/CIT_2/2026-09-15')
        ->assertStatus(422)
        ->assertExactJson([
            'errors' => ['One or both selected stops are not available.'],
        ]);

    Http::assertSentCount(2);
});

test('it returns invalid stop errors from get one checks', function (string $stop, string $parameter) {
    Http::fake([
        'https://queropassagem.example/ws_v4/stops/CIT_1' => Http::response([
            'id' => 'CIT_1',
            'name' => 'Campinas',
            'state' => 'SP',
            'type' => 'city',
        ]),
        'https://queropassagem.example/ws_v4/stops/INVALID' => Http::response([], 404),
    ]);

    getJson("/api/search/{$stop}/".($stop === 'INVALID' ? 'CIT_1' : 'INVALID').'/2026-09-15')
        ->assertStatus(422)
        ->assertExactJson([
            'errors' => ["Parameter '{$parameter}' is missing or invalid."],
        ]);
})->with([
    'from' => ['INVALID', 'from'],
    'to' => ['CIT_1', 'to'],
]);

test('it preserves search provider errors and status', function () {
    Http::fake([
        'https://queropassagem.example/ws_v4/stops/*' => Http::response([
            'id' => 'CIT_1',
            'name' => 'Campinas',
            'state' => 'SP',
            'type' => 'city',
        ]),
        'https://queropassagem.example/ws_v4/new/search/*' => Http::response([
            'errors' => ["Parameter 'travelDate' is missing or invalid."],
        ], 422),
    ]);

    getJson('/api/search/CIT_1/CIT_2/not-a-date')
        ->assertStatus(422)
        ->assertExactJson([
            'errors' => ["Parameter 'travelDate' is missing or invalid."],
        ]);
});
