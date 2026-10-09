<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\getJson;

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
