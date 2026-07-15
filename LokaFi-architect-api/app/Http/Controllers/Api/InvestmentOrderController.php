<?php

namespace App\Http\Controllers\Api;

use App\Models\Asset;
use App\Models\Wallet;
use App\Models\InvestmentOrder;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;

class InvestmentOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $orders = $request->user()
            ->investmentOrders()
            ->with(['asset', 'wallet'])
            ->latest('ordered_at')
            ->paginate(10)
            ->withQueryString();

        return response()->json([
            'message' => 'Data order investasi berhasil diambil',
            'data' => $orders,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'in:buy,sell'],
            'asset_id' => ['required', 'exists:assets,id'],
            'wallet_id' => ['required', 'exists:wallets,id'],
            'quantity' => ['required', 'numeric', 'min:0.00000001'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'fee' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string'],
            'ordered_at' => ['nullable', 'date'],
        ]);

        $user = $request->user();

        $asset = Asset::where('id', $data['asset_id'])
            ->where('is_active', true)
            ->firstOrFail();

        $wallet = Wallet::where('id', $data['wallet_id'])
            ->where('user_id', $user->id)
            ->firstOrFail();

        if ($wallet->type !== 'investment_cash') {
            return response()->json([
                'message' => 'Order investasi harus menggunakan wallet type investment_cash',
            ], 422);
        }

        $quantity = (float) $data['quantity'];
        $price = isset($data['price']) && (float) $data['price'] > 0
            ? (float) $data['price']
            : (float) $asset->current_price;

        $fee = isset($data['fee']) ? (float) $data['fee'] : 0;

        if ($price <= 0) {
            return response()->json([
                'message' => 'Harga asset belum valid',
            ], 422);
        }

        $grossAmount = round($quantity * $price, 2);

        if ($data['type'] === 'buy') {
            $netAmount = round($grossAmount + $fee, 2);
        } else {
            $netAmount = round($grossAmount - $fee, 2);
        }

        if ($netAmount < 0) {
            return response()->json([
                'message' => 'Fee tidak boleh lebih besar dari nilai transaksi',
            ], 422);
        }

        $order = DB::transaction(function () use (
            $user,
            $asset,
            $wallet,
            $data,
            $quantity,
            $price,
            $fee,
            $grossAmount,
            $netAmount
        ) {
            $wallet->refresh();

            if ($data['type'] === 'buy') {
                if ((float) $wallet->current_balance < $netAmount) {
                    abort(422, 'Saldo investment wallet tidak cukup');
                }

                $wallet->decrement('current_balance', $netAmount);
            }

            if ($data['type'] === 'sell') {
                $holdingQuantity = $this->getHoldingQuantity($user->id, $asset->id);

                if ($holdingQuantity < $quantity) {
                    abort(422, 'Jumlah asset yang dijual melebihi holding');
                }

                $wallet->increment('current_balance', $netAmount);
            }

            return InvestmentOrder::create([
                'user_id' => $user->id,
                'asset_id' => $asset->id,
                'wallet_id' => $wallet->id,
                'type' => $data['type'],
                'mode' => 'simulation',
                'status' => 'executed',
                'quantity' => $quantity,
                'price' => $price,
                'fee' => $fee,
                'gross_amount' => $grossAmount,
                'net_amount' => $netAmount,
                'currency' => $asset->currency,
                'note' => $data['note'] ?? null,
                'ordered_at' => $data['ordered_at'] ?? now(),
                'metadata' => [
                    'source' => 'portfolio_simulator',
                    'asset_symbol' => $asset->symbol,
                    'asset_type' => $asset->asset_type,
                ],
            ]);
        });

        return response()->json([
            'message' => 'Order investasi berhasil dieksekusi dalam mode simulasi',
            'data' => $order->load(['asset', 'wallet']),
        ], 201);
    }

    private function getHoldingQuantity(int $userId, int $assetId): float
    {
        $buyQuantity = InvestmentOrder::where('user_id', $userId)
            ->where('asset_id', $assetId)
            ->where('type', 'buy')
            ->where('status', 'executed')
            ->sum('quantity');

        $sellQuantity = InvestmentOrder::where('user_id', $userId)
            ->where('asset_id', $assetId)
            ->where('type', 'sell')
            ->where('status', 'executed')
            ->sum('quantity');

        return (float) $buyQuantity - (float) $sellQuantity;
    }
}
