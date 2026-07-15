<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Invoice\StoreInvoiceRequest;
use App\Http\Requests\Invoice\UpdateInvoiceRequest;
use App\Http\Requests\Invoice\VerifyInvoicePaymentRequest;
use App\Models\Invoice;
use App\Services\InvoiceService;
use App\Services\StellarPaymentVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function __construct(
        private readonly InvoiceService $invoiceService,
        private readonly StellarPaymentVerificationService $paymentVerificationService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->invoiceService->refreshExpiredForUser($request->user());

        $invoices = $request->user()
            ->invoices()
            ->with('latestStellarPayment')
            ->latest()
            ->get();

        return response()->json([
            'message' => 'Data invoice berhasil diambil',
            'data' => $invoices,
        ]);
    }

    public function store(StoreInvoiceRequest $request): JsonResponse
    {
        $invoice = $this->invoiceService->create(
            $request->user(),
            $request->validated(),
        );

        return response()->json([
            'message' => 'Invoice Testnet berhasil dibuat',
            'data' => $invoice->load(['user:id,name', 'latestStellarPayment']),
        ], 201);
    }

    public function show(Invoice $invoice): JsonResponse
    {
        $this->ensureInvoiceBelongsToUser($invoice);

        $invoice = $this->invoiceService->refreshExpired($invoice);

        return response()->json([
            'message' => 'Detail invoice berhasil diambil',
            'data' => $invoice->load(['user:id,name', 'latestStellarPayment']),
        ]);
    }

    public function update(UpdateInvoiceRequest $request, Invoice $invoice): JsonResponse
    {
        $this->ensureInvoiceBelongsToUser($invoice);

        $invoice = $this->invoiceService->update(
            $request->user(),
            $invoice,
            $request->validated(),
        );

        return response()->json([
            'message' => 'Invoice berhasil diupdate',
            'data' => $invoice->load(['user:id,name', 'latestStellarPayment']),
        ]);
    }

    public function destroy(Invoice $invoice): JsonResponse
    {
        $this->ensureInvoiceBelongsToUser($invoice);

        $invoice = $this->invoiceService->cancel($invoice);

        return response()->json([
            'message' => 'Invoice berhasil dibatalkan',
            'data' => $invoice->load(['user:id,name', 'latestStellarPayment']),
        ]);
    }

    public function verifyPayment(VerifyInvoicePaymentRequest $request, Invoice $invoice): JsonResponse
    {
        $this->ensureInvoiceBelongsToUser($invoice);

        $result = $this->paymentVerificationService->verify(
            $invoice,
            $request->validated()['transaction_hash'],
        );

        return response()->json([
            'message' => 'Pembayaran Stellar Testnet berhasil diverifikasi',
            'data' => $result,
        ]);
    }

    private function ensureInvoiceBelongsToUser(Invoice $invoice): void
    {
        if ($invoice->user_id !== request()->user()->id) {
            abort(403, 'Kamu tidak punya akses ke invoice ini');
        }
    }
}
