<?php

namespace App\Http\Controllers;

use App\Services\QueroPassagemService;

class StopController extends Controller
{
    public function __construct(private QueroPassagemService $queroPassagem) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function __invoke(): array
    {
        return $this->queroPassagem->stops();
    }
}
