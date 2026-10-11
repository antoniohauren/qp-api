<?php

namespace App\Http\Controllers;

use App\Http\Requests\SeatRequest;
use App\Services\QueroPassagemService;
use Illuminate\Http\JsonResponse;

class SeatController extends Controller
{
    public function __invoke(SeatRequest $request, QueroPassagemService $queroPassagem): JsonResponse
    {
        $validated = $request->validated();

        return response()->json($queroPassagem->seats($validated['travelId']));
    }
}
