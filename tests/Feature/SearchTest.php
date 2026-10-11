<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

use function Pest\Laravel\getJson;
use function Pest\Laravel\json;
use function Pest\Laravel\travelTo;

beforeEach(function () {
    travelTo(now()->setDateTime(2026, 9, 14, 12, 0));

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

test('it searches trips with allowed cities and stations at either end', function (string $fromType, string $toType, string $fromState, string $toState) {
    $from = $fromType === 'station' ? 'ROD_1' : 'CIT_1';
    $to = $toType === 'station' ? 'ROD_2' : 'CIT_2';
    $searchUrl = "https://queropassagem.example/ws_v4/new/search/{$from}/{$to}/2026-09-15/DDE";

    Http::fake([
        "https://queropassagem.example/ws_v4/stops/{$from}" => Http::response([
            'id' => $from, 'name' => 'Origem', 'state' => $fromState, 'type' => $fromType,
        ]),
        "https://queropassagem.example/ws_v4/stops/{$to}" => Http::response([
            'id' => $to, 'name' => 'Destino', 'state' => $toState, 'type' => $toType,
        ]),
        $searchUrl => Http::response([]),
    ]);

    getJson("/api/search/{$from}/{$to}/2026-09-15")
        ->assertOk()
        ->assertExactJson([]);

    Http::assertSent(fn (Request $request): bool => $request->url() === $searchUrl);
})->with([
    'SP station origin' => ['station', 'city', 'SP', 'PR'],
    'PR station origin' => ['station', 'city', 'PR', 'SP'],
    'SP station destination' => ['city', 'station', 'PR', 'SP'],
    'PR station destination' => ['city', 'station', 'SP', 'PR'],
    'stations SP to PR' => ['station', 'station', 'SP', 'PR'],
    'stations PR to SP' => ['station', 'station', 'PR', 'SP'],
    'cities PR to SP' => ['city', 'city', 'PR', 'SP'],
    'cities within SP' => ['city', 'city', 'SP', 'SP'],
    'cities within PR' => ['city', 'city', 'PR', 'PR'],
]);

test('it returns 422 without searching for a forbidden city or unsupported stop type', function (string $stopId, string $field, string $state, string $type) {
    Http::fake([
        "https://queropassagem.example/ws_v4/stops/{$stopId}" => Http::response([
            'name' => 'Localidade', 'state' => $state, 'type' => $type,
        ]),
        'https://queropassagem.example/ws_v4/stops/*' => Http::response([
            'name' => 'Campinas', 'state' => 'SP', 'type' => 'city',
        ]),
    ]);

    getJson('/api/search/CIT_1/CIT_2/2026-09-15')
        ->assertUnprocessable()
        ->assertOnlyInvalid([$field => 'The selected stop is not available.']);

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/new/search/'));
})->with([
    'city outside allowed states at origin' => ['CIT_1', 'from', 'RJ', 'city'],
    'unsupported origin type' => ['CIT_1', 'from', 'SP', 'unknown'],
    'unsupported destination type' => ['CIT_2', 'to', 'PR', 'unknown'],
]);

test('it blocks stations outside allowed states before searching', function (string $fromState, string $toState, array $fields) {
    Http::fake([
        'https://queropassagem.example/ws_v4/stops/ROD_1' => Http::response([
            'id' => 'ROD_1', 'name' => 'Origem', 'state' => $fromState, 'type' => 'station',
        ]),
        'https://queropassagem.example/ws_v4/stops/ROD_2' => Http::response([
            'id' => 'ROD_2', 'name' => 'Destino', 'state' => $toState, 'type' => 'station',
        ]),
    ]);

    getJson('/api/search/ROD_1/ROD_2/2026-09-15')
        ->assertUnprocessable()
        ->assertOnlyInvalid($fields);

    Http::assertSentCount(2);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/new/search/'));
})->with([
    'blocked origin' => ['RJ', 'PR', ['from' => 'The selected stop is not available.']],
    'blocked destination' => ['SP', 'RJ', ['to' => 'The selected stop is not available.']],
    'both blocked' => ['RJ', 'RJ', ['from' => 'The selected stop is not available.', 'to' => 'The selected stop is not available.']],
]);

test('it rejects identical origin and destination stops', function () {
    getJson('/api/search/CIT_1/CIT_1/2026-09-15')
        ->assertUnprocessable()
        ->assertOnlyInvalid(['from' => 'The from field and to must be different.']);

    Http::assertNothingSent();
});

test('it returns 422 for invalid travel dates without contacting the provider', function (string $date, string $message) {
    getJson("/api/search/CIT_1/CIT_2/{$date}")
        ->assertUnprocessable()
        ->assertOnlyInvalid(['travelDate' => $message]);

    Http::assertNothingSent();
})->with([
    'malformed' => ['not-a-date', 'The travel date field must match the format Y-m-d.'],
    'impossible date' => ['2026-02-30', 'The travel date field must match the format Y-m-d.'],
    'wrong format' => ['2026-9-15', 'The travel date field must match the format Y-m-d.'],
    'yesterday' => ['2026-09-13', 'The travel date field must be a date after or equal to today.'],
]);

test('it accepts today in the application timezone', function () {
    $originalTimezone = date_default_timezone_get();
    config()->set('app.timezone', 'America/Sao_Paulo');
    date_default_timezone_set(config('app.timezone'));
    travelTo(Carbon::parse('2026-09-15 01:00:00', 'UTC'));
    $searchUrl = 'https://queropassagem.example/ws_v4/new/search/CIT_1/CIT_2/2026-09-14/DDE';
    Http::fake([
        'https://queropassagem.example/ws_v4/stops/*' => Http::response([
            'name' => 'Campinas', 'state' => 'SP', 'type' => 'city',
        ]),
        $searchUrl => Http::response([]),
    ]);

    try {
        getJson('/api/search/CIT_1/CIT_2/2026-09-14')
            ->assertOk()
            ->assertExactJson([]);

        Http::assertSent(fn (Request $request): bool => $request->url() === $searchUrl);
    } finally {
        date_default_timezone_set($originalTimezone);
    }
});

test('it uses route values over query and body values for validation and search', function (array $body, string $query) {
    $searchUrl = 'https://queropassagem.example/ws_v4/new/search/CIT_1/CIT_2/2026-09-15/DDE';
    Http::fake([
        'https://queropassagem.example/ws_v4/stops/CIT_1' => Http::response([
            'name' => 'Campinas', 'state' => 'SP', 'type' => 'city',
        ]),
        'https://queropassagem.example/ws_v4/stops/CIT_2' => Http::response([
            'name' => 'Curitiba', 'state' => 'PR', 'type' => 'city',
        ]),
        $searchUrl => Http::response([]),
    ]);

    json('GET', '/api/search/CIT_1/CIT_2/2026-09-15'.$query, $body)
        ->assertOk()
        ->assertExactJson([]);

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request): bool => $request->url() === $searchUrl);
})->with([
    'query' => [[], '?from=INVALID&to=INVALID&travelDate=not-a-date'],
    'body' => [['from' => 'INVALID', 'to' => 'INVALID', 'travelDate' => 'not-a-date'], ''],
    'query and body' => [['from' => 'BODY', 'to' => 'BODY', 'travelDate' => '2020-01-01'], '?from=QUERY&to=QUERY&travelDate=not-a-date'],
]);

test('it returns 422 when valid query and body values try to mask invalid route values', function (string $path, string $field) {
    json('GET', $path.'?from=CIT_1&to=CIT_2&travelDate=2026-09-15', [
        'from' => 'CIT_1', 'to' => 'CIT_2', 'travelDate' => '2026-09-15',
    ])
        ->assertUnprocessable()
        ->assertOnlyInvalid([$field]);

    Http::assertNothingSent();
})->with([
    'identical stops' => ['/api/search/CIT_1/CIT_1/2026-09-15', 'from'],
    'malformed date' => ['/api/search/CIT_1/CIT_2/not-a-date', 'travelDate'],
    'past date' => ['/api/search/CIT_1/CIT_2/2026-09-13', 'travelDate'],
]);

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
        ->assertUnprocessable()
        ->assertOnlyInvalid(['to' => 'The selected stop is not available.']);

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
        ->assertUnprocessable()
        ->assertOnlyInvalid([$parameter => "Parameter '{$parameter}' is missing or invalid."]);

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/new/search/'));
})->with([
    'from' => ['INVALID', 'from'],
    'to' => ['CIT_1', 'to'],
]);

test('it returns controlled errors when the search provider fails', function (int $providerStatus, int $publicStatus) {
    Http::fake([
        'https://queropassagem.example/ws_v4/stops/*' => Http::response([
            'id' => 'CIT_1',
            'name' => 'Campinas',
            'state' => 'SP',
            'type' => 'city',
        ]),
        'https://queropassagem.example/ws_v4/new/search/*' => Http::response([
            'errors' => ["Parameter 'travelDate' is missing or invalid."],
        ], $providerStatus),
    ]);

    getJson('/api/search/CIT_1/CIT_2/2026-09-15')
        ->assertStatus($publicStatus)
        ->assertExactJson([
            'errors' => ['The travel provider is currently unavailable.'],
        ]);
})->with([
    'invalid parameter' => [422, 502],
    'not found' => [404, 502],
    'rate limited' => [429, 502],
    'server error' => [500, 502],
]);

test('it returns integration errors instead of invalid parameters when stop lookup fails', function (string $stopId, int $providerStatus, int $publicStatus) {
    $log = Log::spy();
    Http::fake([
        "https://queropassagem.example/ws_v4/stops/{$stopId}" => Http::response(['errors' => ['private provider details']], $providerStatus),
        'https://queropassagem.example/ws_v4/stops/*' => Http::response([
            'name' => 'Campinas', 'state' => 'SP', 'type' => 'city',
        ]),
    ]);

    getJson('/api/search/CIT_1/CIT_2/2026-09-15')
        ->assertStatus($publicStatus)
        ->assertExactJson(['errors' => ['The travel provider is currently unavailable.']]);

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/new/search/'));
    $log->shouldHaveReceived('warning')->once()->with('Travel provider request failed.', [
        'exception' => RequestException::class,
        'status' => $providerStatus,
        'route' => 'search',
    ]);
    $log->shouldNotHaveReceived('error');
})->with(['origin' => 'CIT_1', 'destination' => 'CIT_2'])->with([
    'unauthorized' => [401, 502],
    'forbidden' => [403, 502],
    'rate limited' => [429, 502],
    'server error' => [500, 502],
]);

test('it returns 502 when destination lookup fails after an invalid origin', function (int $originStatus) {
    Http::fake([
        'https://queropassagem.example/ws_v4/stops/CIT_1' => Http::response([
            'name' => 'Rio de Janeiro', 'state' => 'RJ', 'type' => 'city',
        ], $originStatus),
        'https://queropassagem.example/ws_v4/stops/CIT_2' => Http::response([
            'errors' => ['private provider details'],
        ], 500),
    ]);

    getJson('/api/search/CIT_1/CIT_2/2026-09-15')
        ->assertStatus(502)
        ->assertExactJson(['errors' => ['The travel provider is currently unavailable.']]);

    Http::assertSentCount(2);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/new/search/'));
})->with([
    'forbidden origin' => 200,
    'missing origin' => 404,
]);
