<?php

namespace App\Http\Controllers;

use App\Services\QueroPassagemService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SeatController extends Controller
{
    public function __invoke(Request $request, QueroPassagemService $queroPassagem): JsonResponse
    {
        $validated = $request->validate([
            'travelId' => ['required', 'string', 'max:255'],
        ]);

        return response()->json($queroPassagem->seats($validated['travelId']));
    }
}
