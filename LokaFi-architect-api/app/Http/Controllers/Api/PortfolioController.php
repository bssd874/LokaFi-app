<?php

namespace App\Http\Controllers\Api;

use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;

class PortfolioController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $portfolio = $this->buildPortfolio($request);

        return response()->json([
            'message' => 'Data portfolio berhasil diambil',
            'data' => $portfolio,
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        $portfolio = $this->buildPortfolio($request);

        return response()->json([
            'message' => 'Summary portfolio berhasil diambil',
            'data' => $portfolio['summary'],
        ]);
    }

    private function buildPortfolio(Request $request): array
    {
        $user = $request->user();

        $orders = $user->investmentOrders()
            ->with('asset')
            ->where('status', 'executed')
            ->oldest('ordered_at')
            ->get();

        $holdings = [];

        foreach ($orders->groupBy('asset_id') as $assetId => $assetOrders) {
            $asset = $assetOrders->first()->asset;

            $buyQuantity = 0;
            $sellQuantity = 0;
            $buyCost = 0;
            $sellProceeds = 0;

            foreach ($assetOrders as $order) {
                if ($order->type === 'buy') {
                    $buyQuantity += (float) $order->quantity;
                    $buyCost += (float) $order->net_amount;
                }

                if ($order->type === 'sell') {
                    $sellQuantity += (float) $order->quantity;
                    $sellProceeds += (float) $order->net_amount;
                }
            }

            $quantity = round($buyQuantity - $sellQuantity, 8);

            if ($quantity <= 0) {
                continue;
            }

            $averagePrice = $buyQuantity > 0
                ? $buyCost / $buyQuantity
                : 0;

            $currentPrice = (float) $asset->current_price;
            $currentValue = round($quantity * $currentPrice, 2);
            $costBasis = round($quantity * $averagePrice, 2);
            $unrealizedProfitLoss = round($currentValue - $costBasis, 2);

            $unrealizedProfitLossPercentage = $costBasis > 0
                ? round(($unrealizedProfitLoss / $costBasis) * 100, 2)
                : 0;

            $holdings[] = [
                'asset_id' => $asset->id,
                'symbol' => $asset->symbol,
                'name' => $asset->name,
                'asset_type' => $asset->asset_type,
                'currency' => $asset->currency,
                'exchange' => $asset->exchange,
                'quantity' => $quantity,
                'average_price' => round($averagePrice, 2),
                'current_price' => $currentPrice,
                'current_value' => $currentValue,
                'cost_basis' => $costBasis,
                'unrealized_profit_loss' => $unrealizedProfitLoss,
                'unrealized_profit_loss_percentage' => $unrealizedProfitLossPercentage,
                'price_change_percentage' => (float) $asset->price_change_percentage,
                'buy_quantity' => round($buyQuantity, 8),
                'sell_quantity' => round($sellQuantity, 8),
                'buy_cost' => round($buyCost, 2),
                'sell_proceeds' => round($sellProceeds, 2),
            ];
        }

        $totalPortfolioValue = round(collect($holdings)->sum('current_value'), 2);
        $totalCostBasis = round(collect($holdings)->sum('cost_basis'), 2);
        $totalUnrealizedProfitLoss = round($totalPortfolioValue - $totalCostBasis, 2);

        $totalUnrealizedProfitLossPercentage = $totalCostBasis > 0
            ? round(($totalUnrealizedProfitLoss / $totalCostBasis) * 100, 2)
            : 0;

        $investmentCashBalance = Wallet::where('user_id', $user->id)
            ->where('type', 'investment_cash')
            ->where('is_active', true)
            ->sum('current_balance');

        return [
            'summary' => [
                'investment_cash_balance' => round((float) $investmentCashBalance, 2),
                'total_portfolio_value' => $totalPortfolioValue,
                'total_cost_basis' => $totalCostBasis,
                'total_unrealized_profit_loss' => $totalUnrealizedProfitLoss,
                'total_unrealized_profit_loss_percentage' => $totalUnrealizedProfitLossPercentage,
                'total_assets' => count($holdings),
                'total_equity' => round($totalPortfolioValue + (float) $investmentCashBalance, 2),
            ],
            'holdings' => $holdings,
        ];
    }
}
