<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Invoice;
use App\Models\StellarPayment;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class StellarPaymentVerificationService
{
    public const HORIZON_TESTNET_URL = 'https://horizon-testnet.stellar.org';
    public const TESTNET_PASSPHRASE = 'Test SDF Network ; September 2015';

    public function __construct(
        private readonly InvoiceService $invoiceService,
        private readonly DefaultCategoryService $defaultCategoryService,
        private readonly TransactionSanitizationService $sanitizer,
    ) {
    }

    public function verify(Invoice $invoice, string $transactionHash): array
    {
        $transactionHash = strtolower($transactionHash);

        $existingPayment = StellarPayment::where('transaction_hash', $transactionHash)->first();

        if ($existingPayment) {
            return $this->returnExistingPaymentForInvoice($existingPayment, $invoice);
        }

        $invoice = $this->invoiceService->refreshExpired($invoice);

        if (!$invoice->isPending()) {
            throw ValidationException::withMessages([
                'invoice' => 'Invoice sudah tidak bisa dibayar.',
            ]);
        }

        $verifiedPayment = $this->verifyTransactionOnTestnet($invoice, $transactionHash);

        $payment = DB::transaction(function () use ($invoice, $transactionHash, $verifiedPayment) {
            $existingPayment = StellarPayment::where('transaction_hash', $transactionHash)
                ->lockForUpdate()
                ->first();

            if ($existingPayment) {
                return $this->returnExistingPaymentForInvoice($existingPayment, $invoice)['payment'];
            }

            $lockedInvoice = Invoice::whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedInvoice = $this->invoiceService->refreshExpired($lockedInvoice);

            if (!$lockedInvoice->isPending()) {
                throw ValidationException::withMessages([
                    'invoice' => 'Invoice sudah tidak bisa dibayar.',
                ]);
            }

            $payment = StellarPayment::create([
                'invoice_id' => $lockedInvoice->id,
                'sender_public_key' => $verifiedPayment['sender_public_key'],
                'receiver_public_key' => $verifiedPayment['receiver_public_key'],
                'asset_code' => StellarPayment::ASSET_CODE_XLM,
                'amount' => $verifiedPayment['amount'],
                'transaction_hash' => $transactionHash,
                'ledger' => $verifiedPayment['ledger'],
                'memo' => $lockedInvoice->payment_memo,
                'network' => StellarPayment::NETWORK_TESTNET,
                'status' => StellarPayment::STATUS_CONFIRMED,
                'confirmed_at' => $verifiedPayment['confirmed_at'],
                'safe_raw_payload' => $verifiedPayment['safe_raw_payload'],
            ]);

            $financeTransaction = $this->createIncomeTransaction($lockedInvoice, $payment);

            $payment->update([
                'transaction_id' => $financeTransaction->id,
            ]);

            $lockedInvoice->update([
                'status' => Invoice::STATUS_PAID,
                'paid_at' => $verifiedPayment['confirmed_at'],
            ]);

            return $payment->fresh();
        });

        return $this->formatVerificationResult($payment);
    }

    private function returnExistingPaymentForInvoice(StellarPayment $payment, Invoice $invoice): array
    {
        if ($payment->invoice_id !== $invoice->id) {
            throw ValidationException::withMessages([
                'transaction_hash' => 'Transaction hash ini sudah digunakan untuk invoice lain.',
            ]);
        }

        return $this->formatVerificationResult($payment);
    }

    private function verifyTransactionOnTestnet(Invoice $invoice, string $transactionHash): array
    {
        $transactionPayload = $this->getHorizonJson("/transactions/{$transactionHash}");

        if (($transactionPayload['successful'] ?? false) !== true) {
            throw ValidationException::withMessages([
                'transaction_hash' => 'Transaksi Stellar ditemukan tetapi statusnya tidak berhasil.',
            ]);
        }

        $networkPassphrase = $transactionPayload['network_passphrase'] ?? null;

        if ($networkPassphrase !== null && $networkPassphrase !== self::TESTNET_PASSPHRASE) {
            throw ValidationException::withMessages([
                'transaction_hash' => 'Transaksi bukan dari Stellar Testnet.',
            ]);
        }

        if (($transactionPayload['memo'] ?? null) !== $invoice->payment_memo) {
            throw ValidationException::withMessages([
                'transaction_hash' => 'Memo transaksi tidak sesuai dengan invoice.',
            ]);
        }

        $operationsPayload = $this->getHorizonJson("/transactions/{$transactionHash}/operations", [
            'limit' => 200,
        ]);

        $operation = $this->findMatchingPaymentOperation(
            $operationsPayload['_embedded']['records'] ?? [],
            $invoice,
        );

        if (!$operation) {
            throw ValidationException::withMessages([
                'transaction_hash' => 'Transaksi tidak berisi pembayaran native XLM yang sesuai dengan invoice.',
            ]);
        }

        $confirmedAt = isset($transactionPayload['created_at'])
            ? Carbon::parse($transactionPayload['created_at'])
            : now();

        return [
            'sender_public_key' => $operation['from'] ?? ($transactionPayload['source_account'] ?? ''),
            'receiver_public_key' => $operation['to'],
            'amount' => $this->normalizeStellarAmount($operation['amount']),
            'ledger' => $transactionPayload['ledger'] ?? null,
            'confirmed_at' => $confirmedAt,
            'safe_raw_payload' => [
                'horizon_url' => self::HORIZON_TESTNET_URL,
                'transaction' => [
                    'hash' => $transactionHash,
                    'ledger' => $transactionPayload['ledger'] ?? null,
                    'successful' => $transactionPayload['successful'] ?? null,
                    'memo' => $transactionPayload['memo'] ?? null,
                    'memo_type' => $transactionPayload['memo_type'] ?? null,
                    'created_at' => $transactionPayload['created_at'] ?? null,
                    'source_account' => $transactionPayload['source_account'] ?? null,
                ],
                'payment_operation' => [
                    'id' => $operation['id'] ?? null,
                    'type' => $operation['type'] ?? null,
                    'from' => $operation['from'] ?? null,
                    'to' => $operation['to'] ?? null,
                    'asset_type' => $operation['asset_type'] ?? null,
                    'amount' => $operation['amount'] ?? null,
                ],
            ],
        ];
    }

    private function getHorizonJson(string $path, array $query = []): array
    {
        $response = Http::acceptJson()
            ->timeout(15)
            ->get(self::HORIZON_TESTNET_URL . $path, $query);

        if ($response->failed()) {
            throw ValidationException::withMessages([
                'transaction_hash' => 'Transaksi tidak ditemukan di Stellar Testnet.',
            ]);
        }

        return $response->json() ?? [];
    }

    private function findMatchingPaymentOperation(array $operations, Invoice $invoice): ?array
    {
        $expectedAmount = $this->normalizeStellarAmount($invoice->stellar_amount);

        foreach ($operations as $operation) {
            if (($operation['type'] ?? null) !== 'payment') {
                continue;
            }

            if (($operation['to'] ?? null) !== $invoice->recipient_public_key) {
                continue;
            }

            if (($operation['asset_type'] ?? null) !== 'native') {
                continue;
            }

            if ($this->normalizeStellarAmount($operation['amount'] ?? '') !== $expectedAmount) {
                continue;
            }

            return $operation;
        }

        return null;
    }

    private function createIncomeTransaction(Invoice $invoice, StellarPayment $payment): Transaction
    {
        $user = $invoice->user()->firstOrFail();
        $wallet = $this->resolveIncomeWallet($user);
        $category = $this->resolveIncomeCategory($user);

        $description = "Stellar invoice: {$invoice->description}";

        $transaction = Transaction::create([
            'user_id' => $user->id,
            'type' => 'income',
            'wallet_id' => $wallet->id,
            'invoice_id' => $invoice->id,
            'stellar_payment_id' => $payment->id,
            'category_id' => $category->id,
            'amount' => $invoice->fiat_amount,
            'fee' => 0,
            'currency' => $invoice->fiat_currency,
            'merchant' => $invoice->customer_name ?: 'Stellar Invoice',
            'description' => $description,
            'note' => "Auto income dari invoice {$invoice->payment_memo}",
            'reference_code' => $invoice->payment_memo,
            'happened_at' => $payment->confirmed_at ?? now(),
            'external_transaction_id' => $payment->transaction_hash,
            'source' => 'stellar',
            'raw_payload' => $payment->safe_raw_payload,
            'sanitized_description' => $this->sanitizer->sanitizeText($description, $user),
            'categorization_status' => 'categorized',
            'category_source' => 'system',
            'categorized_at' => now(),
        ]);

        Wallet::whereKey($wallet->id)
            ->lockForUpdate()
            ->firstOrFail()
            ->increment('current_balance', $invoice->fiat_amount);

        return $transaction->fresh(['wallet', 'category']);
    }

    private function resolveIncomeWallet(User $user): Wallet
    {
        $wallet = Wallet::where('user_id', $user->id)
            ->where('currency', InvoiceService::FIAT_CURRENCY)
            ->where('is_active', true)
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        if ($wallet) {
            return $wallet;
        }

        return $user->wallets()->create([
            'name' => 'Stellar Invoice Income',
            'type' => 'cash',
            'currency' => InvoiceService::FIAT_CURRENCY,
            'opening_balance' => 0,
            'current_balance' => 0,
            'is_active' => true,
        ]);
    }

    private function resolveIncomeCategory(User $user): Category
    {
        $this->defaultCategoryService->ensureForUser($user);

        return $user->categories()
            ->where('type', 'income')
            ->orderByRaw("CASE WHEN name = 'Pemasukan' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->firstOrFail();
    }

    private function normalizeStellarAmount(mixed $value): string
    {
        $amount = trim((string) $value);

        if (!preg_match('/^\d+(\.\d+)?$/', $amount)) {
            return '__invalid__';
        }

        [$integer, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        $extraPrecision = substr($fraction, 7);

        if ($extraPrecision !== '' && trim($extraPrecision, '0') !== '') {
            return '__invalid__';
        }

        $integer = ltrim($integer, '0') ?: '0';
        $fraction = str_pad(substr($fraction, 0, 7), 7, '0');

        return "{$integer}.{$fraction}";
    }

    private function formatVerificationResult(StellarPayment $payment): array
    {
        $payment = $payment->fresh([
            'invoice.user:id,name',
            'transaction.wallet',
            'transaction.category',
        ]);

        return [
            'invoice' => $payment->invoice,
            'payment' => $payment,
            'finance_transaction' => $payment->transaction,
        ];
    }
}
