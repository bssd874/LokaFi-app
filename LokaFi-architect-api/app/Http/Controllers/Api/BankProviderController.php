<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BrankasService;
use Illuminate\Http\JsonResponse;

class BankProviderController extends Controller
{
    public function __construct(private readonly BrankasService $brankasService)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json([
            'message' => 'Data provider bank berhasil diambil',
            'data' => $this->brankasService->providers(),
        ]);
    }
}
