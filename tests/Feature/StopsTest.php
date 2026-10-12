<?php

namespace Tests\Feature;

use App\Services\QueroPassagemService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\travelTo;

test('it classifies official stop names consistently in listing and internal lookup', function (array $stop, bool $available) {
    config()->set('services.quero_passagem.url', 'https://queropassagem.example');

    Http::preventStrayRequests();
    Http::fake([
        'https://queropassagem.example/ws_v4/stops' => Http::response([$stop]),
        'https://queropassagem.example/ws_v4/stops/STOP_1' => Http::response($stop),
    ]);

    getJson('/api/stops')
        ->assertOk()
        ->assertExactJson([[...$stop, 'available' => $available]]);

    expect(app(QueroPassagemService::class)->stop('STOP_1'))
        ->toBe([...$stop, 'available' => $available]);
})->with([
    'SP city with suffix' => [['name' => 'São Paulo, SP - TODOS', 'type' => 'city'], true],
    'PR city with suffix' => [['name' => 'Curitiba, PR - TODOS', 'type' => 'city'], true],
    'RJ city with suffix' => [['name' => 'Rio de Janeiro, RJ - TODOS', 'type' => 'city'], false],
    'SP station with suffix' => [['name' => 'São Paulo, SP - Barra Funda', 'type' => 'station'], true],
    'PR station with suffix' => [['name' => 'Curitiba, PR - Rodoviária', 'type' => 'station'], true],
    'RJ station with suffix' => [['name' => 'Rio de Janeiro, RJ - Novo Rio', 'type' => 'station'], false],
    'SP station without suffix' => [['name' => 'São Paulo, SP', 'type' => 'station'], true],
    'PR station without suffix' => [['name' => 'Curitiba, PR', 'type' => 'station'], true],
    'explicit allowed state takes precedence' => [['name' => 'Terminal, RJ - Rodoviária', 'state' => 'SP', 'type' => 'station'], true],
    'explicit blocked state takes precedence' => [['name' => 'Terminal, SP - Rodoviária', 'state' => 'RJ', 'type' => 'station'], false],
    'unknown type' => [['name' => 'São Paulo, SP - TODOS', 'type' => 'unknown'], false],
    'missing state in name' => [['name' => 'São Paulo', 'type' => 'city'], false],
]);

test('it returns stops marked available for cities in Sao Paulo and Parana', function () {
    config()->set([
        'services.quero_passagem.url' => 'https://queropassagem.example',
        'services.quero_passagem.username' => 'user',
        'services.quero_passagem.password' => 'secret',
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'https://queropassagem.example/ws_v4/stops' => Http::response([
            [
                'id' => 'CIT_3583',
                'name' => 'Bocaiuva do Sul, PR',
                'url' => 'bocaiuva-do-sul-pr',
                'type' => 'city',
            ],
            [
                'id' => 'CIT_440',
                'name' => 'Bodoco, PE',
                'url' => 'bodoco',
                'type' => 'city',
                'substops' => [[
                    'id' => 'ROD_443',
                    'name' => 'Bodoco, PE',
                    'url' => 'bodoco-ma',
                    'type' => 'station',
                    'sub_type' => null,
                ]],
            ],
            [
                'id' => 'CIT_9668',
                'name' => 'Sao Paulo, SP',
                'url' => 'sao-paulo-sp',
                'type' => 'city',
            ],
        ]),
    ]);

    $response = getJson('/api/stops');

    $response->assertOk()->assertExactJson([
        [
            'id' => 'CIT_3583',
            'name' => 'Bocaiuva do Sul, PR',
            'url' => 'bocaiuva-do-sul-pr',
            'type' => 'city',
            'available' => true,
        ],
        [
            'id' => 'CIT_440',
            'name' => 'Bodoco, PE',
            'url' => 'bodoco',
            'type' => 'city',
            'substops' => [[
                'id' => 'ROD_443',
                'name' => 'Bodoco, PE',
                'url' => 'bodoco-ma',
                'type' => 'station',
                'sub_type' => null,
            ]],
            'available' => false,
        ],
        [
            'id' => 'CIT_9668',
            'name' => 'Sao Paulo, SP',
            'url' => 'sao-paulo-sp',
            'type' => 'city',
            'available' => true,
        ],
    ]);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://queropassagem.example/ws_v4/stops'
        && $request->hasHeader('Accept', 'application/json')
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('user:secret')));
});

test('it internally retrieves one stop marked available when it is in Parana', function () {
    config()->set([
        'services.quero_passagem.url' => 'https://queropassagem.example',
        'services.quero_passagem.username' => 'user',
        'services.quero_passagem.password' => 'secret',
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'https://queropassagem.example/ws_v4/stops/CIT_2325' => Http::response([
            'id' => 'CIT_2325',
            'displayName' => 'Curitiba, PR',
            'name' => 'Curitiba',
            'state' => 'PR',
            'url' => 'curitiba-pr',
            'latitude' => '0.00000000',
            'longitude' => '0.00000000',
            'type' => 'city',
            'station' => [],
        ]),
    ]);

    $stop = app(QueroPassagemService::class)->stop('CIT_2325');

    expect($stop)->toBe([
        'id' => 'CIT_2325',
        'displayName' => 'Curitiba, PR',
        'name' => 'Curitiba',
        'state' => 'PR',
        'url' => 'curitiba-pr',
        'latitude' => '0.00000000',
        'longitude' => '0.00000000',
        'type' => 'city',
        'station' => [],
        'available' => true,
    ]);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://queropassagem.example/ws_v4/stops/CIT_2325'
        && $request->hasHeader('Accept', 'application/json')
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('user:secret')));
});

test('it preserves reserved characters in the provider stop detail path segment', function () {
    config()->set('services.quero_passagem.url', 'https://queropassagem.example');
    $stop = ['id' => 'CIT_1#?%+', 'name' => 'Curitiba', 'state' => 'PR', 'type' => 'city'];
    $stopUrl = 'https://queropassagem.example/ws_v4/stops/CIT_1%23%3F%25%2B';

    Http::preventStrayRequests();
    Http::fake([$stopUrl => Http::response($stop)]);

    expect(app(QueroPassagemService::class)->stop('CIT_1#?%+'))
        ->toBe([...$stop, 'available' => true]);

    Http::assertSent(fn (Request $request): bool => $request->url() === $stopUrl);
});

test('it returns 404 for public stop details without contacting the provider', function () {
    config()->set('services.quero_passagem.url', 'https://queropassagem.example');
    Http::preventStrayRequests();
    Http::fake([
        'https://queropassagem.example/ws_v4/stops/CIT_1' => Http::response([
            'id' => 'CIT_1', 'name' => 'Campinas', 'state' => 'SP', 'type' => 'city',
        ]),
    ]);

    getJson('/api/stops/CIT_1')->assertNotFound();

    Http::assertNothingSent();
});

test('it propagates a missing stop response to internal callers', function () {
    config()->set('services.quero_passagem.url', 'https://queropassagem.example');
    Http::preventStrayRequests();
    Http::fake([
        'https://queropassagem.example/ws_v4/stops/INVALID' => Http::response(['errors' => ['private provider details']], 404),
    ]);

    expect(fn () => app(QueroPassagemService::class)->stop('INVALID'))
        ->toThrow(RequestException::class, '404');
});

test('it returns controlled errors for stop listing provider failures', function (int $providerStatus, int $publicStatus) {
    config()->set('services.quero_passagem.url', 'https://queropassagem.example');
    Http::preventStrayRequests();
    Http::fake([
        'https://queropassagem.example/ws_v4/stops' => Http::response(['errors' => ['private provider details']], $providerStatus),
    ]);

    getJson('/api/stops')
        ->assertStatus($publicStatus)
        ->assertExactJson(['errors' => ['The travel provider is currently unavailable.']]);
})->with([
    'unauthorized' => [401, 502],
    'forbidden' => [403, 502],
    'rate limited' => [429, 502],
    'server error' => [500, 502],
    'unavailable' => [503, 502],
]);

test('it propagates provider failures to internal stop callers', function (int $providerStatus) {
    config()->set('services.quero_passagem.url', 'https://queropassagem.example');
    Http::preventStrayRequests();
    Http::fake([
        'https://queropassagem.example/ws_v4/stops/CIT_1' => Http::response([], $providerStatus),
    ]);

    expect(fn () => app(QueroPassagemService::class)->stop('CIT_1'))
        ->toThrow(RequestException::class, (string) $providerStatus);
})->with([401, 403, 429, 500, 503]);

test('it treats a missing provider catalog as 502 rather than a missing stop', function () {
    config()->set('services.quero_passagem.url', 'https://queropassagem.example');
    Http::preventStrayRequests();
    Http::fake(['https://queropassagem.example/ws_v4/stops' => Http::response([], 404)]);

    getJson('/api/stops')
        ->assertStatus(502)
        ->assertExactJson(['errors' => ['The travel provider is currently unavailable.']]);
});

test('it returns 502 with safe diagnostics when a provider connection fails', function (string $route, string $providerPath, string $routeName, string $reason) {
    travelTo(now()->setDateTime(2026, 9, 14, 12, 0));

    config()->set('services.quero_passagem.url', 'https://queropassagem.example');
    $log = Log::spy();
    Http::preventStrayRequests();
    Http::fake([
        'https://queropassagem.example/ws_v4/'.$providerPath => Http::failedConnection($reason),
        'https://queropassagem.example/ws_v4/stops/*' => Http::response([
            'name' => 'Campinas', 'state' => 'SP', 'type' => 'city',
        ]),
    ]);

    $response = $routeName === 'seats'
        ? postJson($route, ['travelId' => 'trip-1'])
        : getJson($route);

    $response->assertStatus(502)
        ->assertExactJson(['errors' => ['The travel provider is currently unavailable.']]);

    $log->shouldHaveReceived('warning')->once()->with('Travel provider connection failed.', [
        'exception' => ConnectionException::class,
        'route' => $routeName,
    ]);
    $log->shouldNotHaveReceived('error');
})->with([
    'listing' => ['/api/stops', 'stops', 'stops'],
    'search origin' => ['/api/search/CIT_1/CIT_2/2026-09-15', 'stops/CIT_1', 'search'],
    'search destination' => ['/api/search/CIT_1/CIT_2/2026-09-15', 'stops/CIT_2', 'search'],
    'search trips' => ['/api/search/CIT_1/CIT_2/2026-09-15', 'new/search/*', 'search'],
    'seats' => ['/api/seats', 'new/seats', 'seats'],
])->with([
    'connection refused' => 'cURL error 7: private connection details',
    'timeout' => 'cURL error 28: private timeout details',
]);
