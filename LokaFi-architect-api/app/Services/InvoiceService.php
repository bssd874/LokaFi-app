<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\StellarWallet;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InvoiceService
{
    public const DEMO_IDR_PER_XLM = 2500;
    public const FIAT_CURRENCY = 'IDR';
    public const STELLAR_ASSET_CODE = 'XLM';

    public function create(User $user, array $data): Invoice
    {
        $this->ensureRecipientBelongsToUserWallet($user, $data['recipient_public_key']);

        return $user->invoices()->create([
            'uuid' => $this->generateUuid(),
            'customer_name' => $data['customer_name'] ?? null,
            'customer_email' => $data['customer_email'] ?? null,
            'description' => $data['description'],
            'fiat_currency' => self::FIAT_CURRENCY,
            'fiat_amount' => $data['fiat_amount'],
            'demo_exchange_rate' => self::DEMO_IDR_PER_XLM,
            'stellar_asset_code' => self::STELLAR_ASSET_CODE,
            'stellar_amount' => $this->calculateDemoXlmAmount($data['fiat_amount']),
            'recipient_public_key' => $data['recipient_public_key'],
            'payment_memo' => $this->generatePaymentMemo(),
            'status' => Invoice::STATUS_PENDING,
            'expires_at' => $data['expires_at'],
        ]);
    }

    public function update(User $user, Invoice $invoice, array $data): Invoice
    {
        $this->refreshExpired($invoice);

        if (!$invoice->isPending()) {
            throw ValidationException::withMessages([
                'status' => 'Invoice hanya bisa diubah saat masih pending.',
            ]);
        }

        if (array_key_exists('recipient_public_key', $data)) {
            $this->ensureRecipientBelongsToUserWallet($user, $data['recipient_public_key']);
        }

        $updates = [];

        foreach (['customer_name', 'customer_email', 'description', 'recipient_public_key', 'expires_at'] as $field) {
            if (array_key_exists($field, $data)) {
                $updates[$field] = $data[$field];
            }
        }

        if (array_key_exists('fiat_amount', $data)) {
            $updates['fiat_amount'] = $data['fiat_amount'];
            $updates['demo_exchange_rate'] = self::DEMO_IDR_PER_XLM;
            $updates['stellar_amount'] = $this->calculateDemoXlmAmount($data['fiat_amount']);
        }

        $invoice->update($updates);

        return $invoice->fresh('user');
    }

    public function cancel(Invoice $invoice): Invoice
    {
        $this->refreshExpired($invoice);

        if ($invoice->status === Invoice::STATUS_PAID) {
            throw ValidationException::withMessages([
                'status' => 'Invoice yang sudah paid tidak bisa dibatalkan.',
            ]);
        }

        if ($invoice->status !== Invoice::STATUS_CANCELLED) {
            $invoice->update(['status' => Invoice::STATUS_CANCELLED]);
        }

        return $invoice->fresh('user');
    }

    public function refreshExpired(Invoice $invoice): Invoice
    {
        if ($invoice->isPending() && $invoice->expires_at->lessThanOrEqualTo(now())) {
            $invoice->update(['status' => Invoice::STATUS_EXPIRED]);
        }

        return $invoice->fresh('user');
    }

    public function refreshExpiredForUser(User $user): void
    {
        $user->invoices()
            ->where('status', Invoice::STATUS_PENDING)
            ->where('expires_at', '<=', now())
            ->update(['status' => Invoice::STATUS_EXPIRED]);
    }

    private function calculateDemoXlmAmount(float|int|string $fiatAmount): string
    {
        $amount = round(((float) $fiatAmount) / self::DEMO_IDR_PER_XLM, 7);

        return number_format($amount, 7, '.', '');
    }

    private function ensureRecipientBelongsToUserWallet(User $user, string $publicKey): void
    {
        $walletExists = StellarWallet::where('user_id', $user->id)
            ->where('public_key', $publicKey)
            ->where('network', 'testnet')
            ->where('wallet_provider', 'freighter')
            ->exists();

        if (!$walletExists) {
            throw ValidationException::withMessages([
                'recipient_public_key' => 'Recipient public key harus berasal dari Stellar wallet Testnet milik kamu.',
            ]);
        }
    }

    private function generateUuid(): string
    {
        do {
            $uuid = (string) Str::uuid();
        } while (Invoice::where('uuid', $uuid)->exists());

        return $uuid;
    }

    private function generatePaymentMemo(): string
    {
        do {
            $memo = 'LOKAFI-' . strtoupper(Str::random(12));
        } while (Invoice::where('payment_memo', $memo)->exists());

        return $memo;
    }
}
