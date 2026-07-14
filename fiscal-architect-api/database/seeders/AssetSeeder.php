<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Asset;

class AssetSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $assets = [
            [
                'symbol' => 'AAPL',
                'name' => 'Apple Inc.',
                'asset_type' => 'us_stock',
                'currency' => 'IDR',
                'exchange' => 'NASDAQ',
                'current_price' => 3500000,
                'price_change_percentage' => 1.25,
            ],
            [
                'symbol' => 'TSLA',
                'name' => 'Tesla Inc.',
                'asset_type' => 'us_stock',
                'currency' => 'IDR',
                'exchange' => 'NASDAQ',
                'current_price' => 5200000,
                'price_change_percentage' => -0.75,
            ],
            [
                'symbol' => 'BBCA',
                'name' => 'Bank Central Asia Tbk',
                'asset_type' => 'idx_stock',
                'currency' => 'IDR',
                'exchange' => 'IDX',
                'current_price' => 9500,
                'price_change_percentage' => 0.85,
            ],
            [
                'symbol' => 'TLKM',
                'name' => 'Telkom Indonesia Tbk',
                'asset_type' => 'idx_stock',
                'currency' => 'IDR',
                'exchange' => 'IDX',
                'current_price' => 2900,
                'price_change_percentage' => -0.35,
            ],
            [
                'symbol' => 'BTC',
                'name' => 'Bitcoin',
                'asset_type' => 'crypto',
                'currency' => 'IDR',
                'exchange' => 'Crypto Market',
                'current_price' => 1050000000,
                'price_change_percentage' => 2.15,
            ],
            [
                'symbol' => 'ETH',
                'name' => 'Ethereum',
                'asset_type' => 'crypto',
                'currency' => 'IDR',
                'exchange' => 'Crypto Market',
                'current_price' => 55000000,
                'price_change_percentage' => 1.65,
            ],
            [
                'symbol' => 'USDIDR',
                'name' => 'US Dollar / Indonesian Rupiah',
                'asset_type' => 'forex',
                'currency' => 'IDR',
                'exchange' => 'Forex',
                'current_price' => 16250,
                'price_change_percentage' => 0.15,
            ],
            [
                'symbol' => 'XAU-IDR',
                'name' => 'Gold Spot IDR',
                'asset_type' => 'gold',
                'currency' => 'IDR',
                'exchange' => 'Gold Market',
                'current_price' => 1750000,
                'price_change_percentage' => 0.45,
            ],
            [
                'symbol' => 'RDPU-DUMMY',
                'name' => 'Reksa Dana Pasar Uang Simulasi',
                'asset_type' => 'mutual_fund',
                'currency' => 'IDR',
                'exchange' => 'Mutual Fund',
                'current_price' => 1000,
                'price_change_percentage' => 0.05,
            ],
            [
                'symbol' => 'RDPT-DUMMY',
                'name' => 'Reksa Dana Pendapatan Tetap Simulasi',
                'asset_type' => 'mutual_fund',
                'currency' => 'IDR',
                'exchange' => 'Mutual Fund',
                'current_price' => 1500,
                'price_change_percentage' => 0.10,
            ],
        ];

        foreach ($assets as $asset) {
            Asset::updateOrCreate(
                ['symbol' => $asset['symbol']],
                array_merge($asset, [
                    'is_active' => true,
                    'metadata' => [
                        'mode' => 'simulation',
                        'note' => 'Harga dummy untuk MVP portfolio simulator',
                    ],
                ])
            );
        }
    }
}
