<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Wallet;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\Wallet\StoreWalletRequest;
use App\Http\Requests\Wallet\UpdateWalletRequest;

class WalletController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index() : JsonResponse
    {
        $wallets = request()->user()
            ->wallets()
            ->latest()
            ->get();

        return response()->json([
            'message' => 'Data wallet berhasil diambil',
            'data' => $wallets,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreWalletRequest $request) : JsonResponse
    {
        $data =  $request->validated();
        $openingBalance = $data['opening_balance'] ?? 0;

        $wallets = request()->user()
            ->wallets()
            ->create([
                'name' => $data['name'],
                'type' => $data['type'],
                'currency' => $data['currency'] ?? 'IDR',
                'opening_balance' => $openingBalance,
                'current_balance' => $openingBalance,
                'is_active' => $data['is_active'] ?? true,
                'provider_code' => $data['provider_code'] ?? null,
                'account_number_masked' => $data['account_number_masked'] ?? null,
                'connection_status' => 'manual',
                'sync_source' => 'manual',
            ]);

        return response()->json([
            'message' => 'Wallet berhasil dibuat',
            'data' => $wallets,
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Wallet $wallet): JsonResponse
    {
        $this->ensureWalletBelongsToUser($wallet);

        return response()->json([
            'message' => 'Detail wallet berhasil diambil',
            'data' => $wallet,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateWalletRequest $request, Wallet $wallet): JsonResponse
    {
        $this->ensureWalletBelongsToUser($wallet);

        $data = $request->validated();

        $wallet->update($data);

        return response()->json([
            'message' => 'Wallet berhasil diupdate',
            'data' => $wallet->fresh(),
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Wallet $wallet): JsonResponse
    {
        $this->ensureWalletBelongsToUser($wallet);

        $wallet->delete();

        return response()->json([
            'message' => 'Wallet berhasil dihapus',
        ]);
    }

    private function ensureWalletBelongsToUser(Wallet $wallet): void
    {
        if ($wallet->user_id !== request()->user()->id) {
            abort(403, 'Kamu tidak punya akses ke wallet ini');
        }
    }
}
