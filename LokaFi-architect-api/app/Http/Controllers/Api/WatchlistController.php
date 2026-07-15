<?php

namespace App\Http\Controllers\Api;

use App\Models\Watchlist;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;

class WatchlistController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $watchlists = $request->user()
            ->watchlists()
            ->with('asset')
            ->latest()
            ->get();

        return response()->json([
            'message' => 'Data watchlist berhasil diambil',
            'data' => $watchlists,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'asset_id' => ['required', 'exists:assets,id'],
        ]);

        $watchlist = Watchlist::firstOrCreate([
            'user_id' => $request->user()->id,
            'asset_id' => $data['asset_id'],
        ]);

        return response()->json([
            'message' => 'Asset berhasil ditambahkan ke watchlist',
            'data' => $watchlist->load('asset'),
        ], 201);
    }

    public function destroy(Request $request, Watchlist $watchlist): JsonResponse
    {
        if ($watchlist->user_id !== $request->user()->id) {
            abort(403, 'Kamu tidak punya akses ke watchlist ini');
        }

        $watchlist->delete();

        return response()->json([
            'message' => 'Asset berhasil dihapus dari watchlist',
        ]);
    }
}
