<?php

namespace App\Http\Controllers;

use App\Http\Requests\SearchRequest;
use App\Services\QueroPassagemService;
use Illuminate\Http\JsonResponse;

class SearchController extends Controller
{
    public function __invoke(SearchRequest $request, QueroPassagemService $queroPassagem): JsonResponse
    {
        $validated = $request->validated();

        return response()->json($queroPassagem->search($validated['from'], $validated['to'], $validated['travelDate']));
    }
}
