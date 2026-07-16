<?php

namespace App\Services;

use App\Models\User;

class DefaultCategoryService
{
    /** @var array<int, true> */
    private array $ensuredUserIds = [];

    private const CATEGORIES = [
        ['name' => 'Pemasukan', 'type' => 'income', 'icon' => 'wallet', 'color' => '#22C55E'],
        ['name' => 'Makanan dan Minuman', 'type' => 'expense', 'icon' => 'utensils', 'color' => '#EF4444'],
        ['name' => 'Transportasi', 'type' => 'expense', 'icon' => 'car', 'color' => '#3B82F6'],
        ['name' => 'Belanja', 'type' => 'expense', 'icon' => 'shopping-bag', 'color' => '#A855F7'],
        ['name' => 'Belanja Online', 'type' => 'expense', 'icon' => 'package', 'color' => '#8B5CF6'],
        ['name' => 'Tagihan', 'type' => 'expense', 'icon' => 'receipt', 'color' => '#F97316'],
        ['name' => 'Kesehatan', 'type' => 'expense', 'icon' => 'heart-pulse', 'color' => '#EC4899'],
        ['name' => 'Pendidikan', 'type' => 'expense', 'icon' => 'graduation-cap', 'color' => '#06B6D4'],
        ['name' => 'Hiburan', 'type' => 'expense', 'icon' => 'gamepad', 'color' => '#F59E0B'],
        ['name' => 'Investasi', 'type' => 'expense', 'icon' => 'line-chart', 'color' => '#10B981'],
        ['name' => 'Transfer', 'type' => 'expense', 'icon' => 'send', 'color' => '#64748B'],
        ['name' => 'Biaya Administrasi', 'type' => 'expense', 'icon' => 'banknote', 'color' => '#64748B'],
        ['name' => 'Donasi', 'type' => 'expense', 'icon' => 'hand-heart', 'color' => '#14B8A6'],
        ['name' => 'Lainnya', 'type' => 'expense', 'icon' => 'tag', 'color' => '#94A3B8'],
    ];

    public function ensureForUser(User $user): void
    {
        if (isset($this->ensuredUserIds[$user->id])) {
            return;
        }

        foreach (self::CATEGORIES as $category) {
            $user->categories()->firstOrCreate(
                [
                    'name' => $category['name'],
                    'type' => $category['type'],
                ],
                [
                    'icon' => $category['icon'],
                    'color' => $category['color'],
                    'is_default' => true,
                ],
            );
        }

        $this->ensuredUserIds[$user->id] = true;
    }
}
