<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Wallet;
use App\Services\FinancialIntelligenceService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class DashboardController extends Controller
{
    public function __construct(
        private readonly FinancialIntelligenceService $financialIntelligence,
    ) {
    }

    public function summary(Request $request): JsonResponse
    {
        $filters = $this->dashboardFilters($request);

        return response()->json([
            'message' => 'Ringkasan dashboard berhasil diambil',
            'data' => $this->financialIntelligence->dashboard($request->user(), $filters),
        ]);
    }

    private function dashboardFilters(Request $request): array
    {
        $timezone = 'Asia/Jakarta';
        $today = CarbonImmutable::now($timezone);
        $defaultStart = $today->subDays(29)->toDateString();
        $defaultEnd = $today->toDateString();

        $data = validator($request->query(), [
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'wallet_id' => ['nullable', 'integer'],
            'source' => ['nullable', 'string', Rule::in([
                'manual',
                'bank_csv',
                'ewallet_csv',
                'stellar',
            ])],
        ])->after(function (Validator $validator) use ($request) {
            if (!$request->filled('wallet_id')) {
                return;
            }

            $exists = Wallet::where('id', (int) $request->query('wallet_id'))
                ->where('user_id', $request->user()->id)
                ->exists();

            if (!$exists) {
                $validator->errors()->add('wallet_id', 'Akun tidak valid atau bukan milik kamu.');
            }
        })->validate();

        return [
            'start_date' => $data['start_date'] ?? $data['from'] ?? $defaultStart,
            'end_date' => $data['end_date'] ?? $data['to'] ?? $defaultEnd,
            'wallet_id' => isset($data['wallet_id']) ? (int) $data['wallet_id'] : null,
            'source' => $data['source'] ?? null,
            'timezone' => $timezone,
            'per_page' => 10,
        ];
    }
}
