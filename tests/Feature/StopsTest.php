<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\getJson;

test('it classifies official stop names consistently in listing and detail', function (array $stop, bool $available) {
    config()->set('services.quero_passagem.url', 'https://queropassagem.example');

    Http::preventStrayRequests();
    Http::fake([
        'https://queropassagem.example/ws_v4/stops' => Http::response([$stop]),
        'https://queropassagem.example/ws_v4/stops/STOP_1' => Http::response($stop),
    ]);

    getJson('/api/stops')
        ->assertOk()
        ->assertExactJson([[...$stop, 'available' => $available]]);

    getJson('/api/stops/STOP_1')
        ->assertOk()
        ->assertExactJson([...$stop, 'available' => $available]);
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

test('it returns one stop marked available when it is in Parana', function () {
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

    $response = getJson('/api/stops/CIT_2325');

    $response->assertOk()->assertExactJson([
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
