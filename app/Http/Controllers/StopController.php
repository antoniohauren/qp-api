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
     * @param  array{name: string, type: string}  $stop
     */
    private function isAvailable(array $stop): bool
    {
        return $stop['type'] === 'city'
            && (str_ends_with($stop['name'], ', SP') || str_ends_with($stop['name'], ', PR'));
    }
}
