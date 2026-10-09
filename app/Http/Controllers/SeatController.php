<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class SeatController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'travelId' => ['required', 'string', 'max:255'],
        ]);

        $response = Http::acceptJson()
            ->withBasicAuth(
                (string) config('services.quero_passagem.username'),
                (string) config('services.quero_passagem.password'),
            )
            ->connectTimeout(3)
            ->timeout(10)
            ->post((string) config('services.quero_passagem.url').'/ws_v4/new/seats', [
                ...$validated,
                'orientation' => 'horizontal',
                'type' => 'matrix',
            ]);

        return response()->json($response->json(), $response->status());
    }
}
