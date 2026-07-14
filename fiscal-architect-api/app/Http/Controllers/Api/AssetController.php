<?php

namespace App\Http\Controllers\Api;

use App\Models\Asset;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;

class AssetController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $operator = config('database.default') === 'pgsql' ? 'ILIKE' : 'LIKE';

        $assets = Asset::query()
            ->where('is_active', true)
            ->when($request->filled('asset_type'), function ($query) use ($request) {
                $query->where('asset_type', $request->asset_type);
            })
            ->when($request->filled('search'), function ($query) use ($request, $operator) {
                $search = '%' . $request->search . '%';

                $query->where(function ($subQuery) use ($search, $operator) {
                    $subQuery->where('symbol', $operator, $search)
                        ->orWhere('name', $operator, $search);
                });
            })
            ->orderBy('asset_type')
            ->orderBy('symbol')
            ->get();

        return response()->json([
            'message' => 'Data asset berhasil diambil',
            'data' => $assets,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'symbol' => ['required', 'string', 'max:30', 'unique:assets,symbol'],
            'name' => ['required', 'string', 'max:150'],
            'asset_type' => ['required', 'string', 'in:us_stock,idx_stock,crypto,forex,gold,mutual_fund'],
            'currency' => ['nullable', 'string', 'max:10'],
            'exchange' => ['nullable', 'string', 'max:100'],
            'current_price' => ['required', 'numeric', 'min:0'],
            'price_change_percentage' => ['nullable', 'numeric'],
            'is_active' => ['nullable', 'boolean'],
            'metadata' => ['nullable', 'array'],
        ]);

        $asset = Asset::create([
            ...$data,
            'currency' => $data['currency'] ?? 'IDR',
            'price_change_percentage' => $data['price_change_percentage'] ?? 0,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return response()->json([
            'message' => 'Asset berhasil dibuat',
            'data' => $asset,
        ], 201);
    }

    public function show(Asset $asset): JsonResponse
    {
        return response()->json([
            'message' => 'Detail asset berhasil diambil',
            'data' => $asset,
        ]);
    }

    public function update(Request $request, Asset $asset): JsonResponse
    {
        $data = $request->validate([
            'symbol' => ['required', 'string', 'max:30', 'unique:assets,symbol,' . $asset->id],
            'name' => ['required', 'string', 'max:150'],
            'asset_type' => ['required', 'string', 'in:us_stock,idx_stock,crypto,forex,gold,mutual_fund'],
            'currency' => ['nullable', 'string', 'max:10'],
            'exchange' => ['nullable', 'string', 'max:100'],
            'current_price' => ['required', 'numeric', 'min:0'],
            'price_change_percentage' => ['nullable', 'numeric'],
            'is_active' => ['nullable', 'boolean'],
            'metadata' => ['nullable', 'array'],
        ]);

        $asset->update([
            ...$data,
            'currency' => $data['currency'] ?? 'IDR',
            'price_change_percentage' => $data['price_change_percentage'] ?? 0,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return response()->json([
            'message' => 'Asset berhasil diupdate',
            'data' => $asset->fresh(),
        ]);
    }

    public function destroy(Asset $asset): JsonResponse
    {
        $asset->update([
            'is_active' => false,
        ]);

        return response()->json([
            'message' => 'Asset berhasil dinonaktifkan',
        ]);
    }
}
