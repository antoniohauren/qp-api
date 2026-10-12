<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class QueroPassagemService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function stops(): array
    {
        $cacheKey = 'quero_passagem.stops.'.hash('sha256', serialize([
            (string) config('services.quero_passagem.url'),
            (string) config('services.quero_passagem.username'),
            (string) config('services.quero_passagem.password'),
        ]));

        return Cache::remember($cacheKey, now()->addHour(), function (): array {
            $stops = $this->client()->get('/ws_v4/stops')->throw()->json();

            return array_map(fn (array $stop): array => [
                ...$stop,
                'available' => $this->isAvailable($stop),
            ], $stops);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function stop(string $stopId): array
    {
        $stop = $this->client()->get('/ws_v4/stops/'.rawurlencode($stopId))->throw()->json();

        return [
            ...$stop,
            'available' => $this->isAvailable($stop),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function search(string $from, string $to, string $travelDate): array
    {
        $trips = $this->client()->get(sprintf(
            '/ws_v4/new/search/%s/%s/%s/%s',
            rawurlencode($from),
            rawurlencode($to),
            rawurlencode($travelDate),
            rawurlencode((string) config('services.quero_passagem.affiliate_code')),
        ))->throw()->json();

        usort($trips, fn (array $firstTrip, array $secondTrip): int => [
            $firstTrip['departure']['date'],
            $firstTrip['departure']['time'],
        ] <=> [
            $secondTrip['departure']['date'],
            $secondTrip['departure']['time'],
        ]);

        return $trips;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function seats(string $travelId): array
    {
        return $this->client()->post('/ws_v4/new/seats', [
            'travelId' => $travelId,
            'orientation' => 'horizontal',
            'type' => 'matrix',
        ])->throw()->json();
    }

    /**
     * @param  array{name: string, state?: string, type: string}  $stop
     */
    private function isAvailable(array $stop): bool
    {
        $allowedStateCodes = ['SP', 'PR'];
        $state = $stop['state'] ?? str($stop['name'])->afterLast(', ')->before(' - ')->toString();
        $isAllowedType = in_array($stop['type'], ['city', 'station'], true);
        $isInAllowedState = in_array($state, $allowedStateCodes, true);

        return $isAllowedType && $isInAllowedState;
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl((string) config('services.quero_passagem.url'))
            ->acceptJson()
            ->withBasicAuth(
                (string) config('services.quero_passagem.username'),
                (string) config('services.quero_passagem.password'),
            )
            ->connectTimeout(3)
            ->timeout(10);
    }
}
