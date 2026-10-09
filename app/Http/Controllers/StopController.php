<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Http;

class StopController extends Controller
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function __invoke(): array
    {
        $stops = Http::acceptJson()
            ->withBasicAuth(
                (string) config('services.quero_passagem.username'),
                (string) config('services.quero_passagem.password'),
            )
            ->connectTimeout(3)
            ->timeout(10)
            ->get((string) config('services.quero_passagem.url').'/ws_v4/stops')
            ->throw()
            ->json();

        return array_map(fn (array $stop): array => [
            ...$stop,
            'available' => $this->isAvailable($stop),
        ], $stops);
    }

    /**
     * @return array<string, mixed>
     */
    public function show(string $stopId): array
    {
        $stop = Http::acceptJson()
            ->withBasicAuth(
                (string) config('services.quero_passagem.username'),
                (string) config('services.quero_passagem.password'),
            )
            ->connectTimeout(3)
            ->timeout(10)
            ->get((string) config('services.quero_passagem.url').'/ws_v4/stops/'.$stopId)
            ->throw()
            ->json();

        return [
            ...$stop,
            'available' => $this->isAvailable($stop),
        ];
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
}
