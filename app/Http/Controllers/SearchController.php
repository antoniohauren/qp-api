<?php

namespace App\Http\Controllers;

use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;

class SearchController extends Controller
{
    public function __invoke(StopController $stops, string $from, string $to, string $travelDate): JsonResponse
    {
        if ($from === $to) {
            return response()->json([
                'errors' => ["Parameters 'from' and 'to' must be different."],
            ], 422);
        }

        try {
            $fromStop = $stops->show($from);
        } catch (RequestException $exception) {
            if (! $exception->response->notFound()) {
                throw $exception;
            }

            return response()->json([
                'errors' => ["Parameter 'from' is missing or invalid."],
            ], 422);
        }

        try {
            $toStop = $stops->show($to);
        } catch (RequestException $exception) {
            if (! $exception->response->notFound()) {
                throw $exception;
            }

            return response()->json([
                'errors' => ["Parameter 'to' is missing or invalid."],
            ], 422);
        }

        if (! $fromStop['available'] || ! $toStop['available']) {
            return response()->json([
                'errors' => ['One or both selected stops are not available.'],
            ], 422);
        }

        $response = Http::acceptJson()
            ->withBasicAuth(
                (string) config('services.quero_passagem.username'),
                (string) config('services.quero_passagem.password'),
            )
            ->connectTimeout(3)
            ->timeout(10)
            ->get(sprintf(
                '%s/ws_v4/new/search/%s/%s/%s/%s',
                config('services.quero_passagem.url'),
                $from,
                $to,
                $travelDate,
                config('services.quero_passagem.affiliate_code'),
            ));

        $trips = $response->json();

        if ($response->successful()) {
            usort($trips, fn (array $firstTrip, array $secondTrip): int => [
                $firstTrip['departure']['date'],
                $firstTrip['departure']['time'],
            ] <=> [
                $secondTrip['departure']['date'],
                $secondTrip['departure']['time'],
            ]);
        }

        return response()->json($trips, $response->status());
    }
}
