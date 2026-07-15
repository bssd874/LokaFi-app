<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Invoice\VerifyInvoicePaymentRequest;
use App\Models\Invoice;
use App\Services\InvoiceService;
use App\Services\StellarPaymentVerificationService;
use Illuminate\Http\JsonResponse;

class PublicInvoiceController extends Controller
{
    public function __construct(
        private readonly InvoiceService $invoiceService,
        private readonly StellarPaymentVerificationService $paymentVerificationService,
    ) {
    }

    public function show(string $uuid): JsonResponse
    {
        $invoice = Invoice::with(['user:id,name', 'latestStellarPayment'])
            ->where('uuid', $uuid)
            ->firstOrFail();

        $invoice = $this->invoiceService->refreshExpired($invoice);

        return response()->json([
            'message' => 'Public invoice berhasil diambil',
            'data' => $invoice->load(['user:id,name', 'latestStellarPayment']),
        ]);
    }

    public function verifyPayment(VerifyInvoicePaymentRequest $request, string $uuid): JsonResponse
    {
        $invoice = Invoice::with(['user:id,name', 'latestStellarPayment'])
            ->where('uuid', $uuid)
            ->firstOrFail();

        $result = $this->paymentVerificationService->verify(
            $invoice,
            $request->validated()['transaction_hash'],
        );

        return response()->json([
            'message' => 'Pembayaran Stellar Testnet berhasil diverifikasi',
            'data' => $result,
        ]);
    }
}
