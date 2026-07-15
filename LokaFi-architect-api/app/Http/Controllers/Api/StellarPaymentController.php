<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StellarPayment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StellarPaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $payments = StellarPayment::query()
            ->whereHas('invoice', function ($query) use ($request) {
                $query->where('user_id', $request->user()->id);
            })
            ->with([
                'invoice:id,uuid,user_id,description,fiat_currency,fiat_amount,stellar_amount,payment_memo,status,paid_at',
                'transaction:id,invoice_id,stellar_payment_id,amount,currency,source,reference_code,happened_at',
            ])
            ->latest('confirmed_at')
            ->get();

        return response()->json([
            'message' => 'Data pembayaran Stellar berhasil diambil',
            'data' => $payments,
        ]);
    }
}
