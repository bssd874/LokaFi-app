<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Stellar\StoreStellarWalletRequest;
use App\Models\StellarWallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StellarWalletController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $wallet = $request->user()
            ->stellarWallets()
            ->where('network', 'testnet')
            ->where('wallet_provider', 'freighter')
            ->latest('connected_at')
            ->first();

        return response()->json([
            'message' => 'Data Stellar wallet berhasil diambil',
            'data' => $wallet,
        ]);
    }

    public function store(StoreStellarWalletRequest $request): JsonResponse
    {
        $data = $request->validated();

        $wallet = StellarWallet::updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'network' => 'testnet',
                'wallet_provider' => 'freighter',
            ],
            [
                'public_key' => $data['public_key'],
                'connected_at' => now(),
            ],
        );

        return response()->json([
            'message' => 'Stellar wallet Testnet berhasil disimpan',
            'data' => $wallet->fresh(),
        ], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->user()
            ->stellarWallets()
            ->where('network', 'testnet')
            ->where('wallet_provider', 'freighter')
            ->delete();

        return response()->json([
            'message' => 'Stellar wallet lokal berhasil diputuskan',
        ]);
    }
}
