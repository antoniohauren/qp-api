<?php

namespace App\Http\Controllers;

use App\Services\QueroPassagemService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;

class SearchController extends Controller
{
    public function __invoke(QueroPassagemService $queroPassagem, string $from, string $to, string $travelDate): JsonResponse
    {
        if ($from === $to) {
            return response()->json([
                'errors' => ["Parameters 'from' and 'to' must be different."],
            ], 422);
        }

        try {
            $fromStop = $queroPassagem->stop($from);
        } catch (RequestException $exception) {
            if (! $exception->response->notFound()) {
                throw $exception;
            }

            return response()->json([
                'errors' => ["Parameter 'from' is missing or invalid."],
            ], 422);
        }

        try {
            $toStop = $queroPassagem->stop($to);
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

        return response()->json($queroPassagem->search($from, $to, $travelDate));
    }
}
