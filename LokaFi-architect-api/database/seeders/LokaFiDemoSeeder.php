<?php

namespace Database\Seeders;

use App\Models\Invoice;
use App\Models\StellarPayment;
use App\Models\StellarWallet;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\DefaultCategoryService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class LokaFiDemoSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::updateOrCreate(
            ['email' => 'demo@lokafi.test'],
            [
                'name' => 'LokaFi Demo Merchant',
                'password' => Hash::make('password'),
                'timezone' => 'Asia/Jakarta',
                'base_currency' => 'IDR',
            ],
        );

        app(DefaultCategoryService::class)->ensureForUser($user);

        $wallet = Wallet::updateOrCreate(
            [
                'user_id' => $user->id,
                'name' => 'Demo Cash Wallet',
            ],
            [
                'type' => 'cash',
                'currency' => 'IDR',
                'opening_balance' => 450000,
                'current_balance' => 575000,
                'is_active' => true,
            ],
        );

        $merchantPublicKey = env(
            'LOKAFI_DEMO_MERCHANT_PUBLIC_KEY',
            'G' . str_repeat('D', 55),
        );

        StellarWallet::updateOrCreate(
            [
                'user_id' => $user->id,
                'network' => 'testnet',
                'wallet_provider' => 'freighter',
            ],
            [
                'public_key' => $merchantPublicKey,
                'connected_at' => now()->subDays(2),
            ],
        );

        Invoice::updateOrCreate(
            ['uuid' => '11111111-1111-4111-8111-111111111111'],
            [
                'user_id' => $user->id,
                'customer_name' => 'Campus Coffee Booth',
                'customer_email' => 'customer.demo@example.test',
                'description' => 'Invoice pending demo merchandise kampus',
                'fiat_currency' => 'IDR',
                'fiat_amount' => 75000,
                'demo_exchange_rate' => 2500,
                'stellar_asset_code' => 'XLM',
                'stellar_amount' => '30.0000000',
                'recipient_public_key' => $merchantPublicKey,
                'payment_memo' => 'LOKAFI-DEMO-PEND',
                'status' => Invoice::STATUS_PENDING,
                'expires_at' => now()->addDays(3),
                'paid_at' => null,
            ],
        );

        $paidInvoice = Invoice::updateOrCreate(
            ['uuid' => '22222222-2222-4222-8222-222222222222'],
            [
                'user_id' => $user->id,
                'customer_name' => 'Student Design Club',
                'customer_email' => 'club.demo@example.test',
                'description' => 'Invoice paid demo jasa desain poster',
                'fiat_currency' => 'IDR',
                'fiat_amount' => 125000,
                'demo_exchange_rate' => 2500,
                'stellar_asset_code' => 'XLM',
                'stellar_amount' => '50.0000000',
                'recipient_public_key' => $merchantPublicKey,
                'payment_memo' => 'LOKAFI-DEMO-PAID',
                'status' => Invoice::STATUS_PAID,
                'expires_at' => now()->addDays(2),
                'paid_at' => now()->subHours(6),
            ],
        );

        $transactionHash = str_repeat('a', 64);
        $senderPublicKey = env(
            'LOKAFI_DEMO_CUSTOMER_PUBLIC_KEY',
            'G' . str_repeat('C', 55),
        );

        $payment = StellarPayment::updateOrCreate(
            ['transaction_hash' => $transactionHash],
            [
                'invoice_id' => $paidInvoice->id,
                'sender_public_key' => $senderPublicKey,
                'receiver_public_key' => $merchantPublicKey,
                'asset_code' => StellarPayment::ASSET_CODE_XLM,
                'amount' => '50.0000000',
                'ledger' => 1234567,
                'memo' => $paidInvoice->payment_memo,
                'network' => StellarPayment::NETWORK_TESTNET,
                'status' => StellarPayment::STATUS_CONFIRMED,
                'confirmed_at' => $paidInvoice->paid_at,
                'safe_raw_payload' => [
                    'seeded_demo' => true,
                    'horizon_url' => 'https://horizon-testnet.stellar.org',
                    'note' => 'Safe demo metadata only. No secret key or mnemonic.',
                ],
            ],
        );

        $incomeCategory = $user->categories()
            ->where('type', 'income')
            ->orderByRaw("CASE WHEN name = 'Pemasukan' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->firstOrFail();

        $transaction = Transaction::updateOrCreate(
            [
                'user_id' => $user->id,
                'source' => 'stellar',
                'external_transaction_id' => $transactionHash,
            ],
            [
                'type' => 'income',
                'wallet_id' => $wallet->id,
                'invoice_id' => $paidInvoice->id,
                'stellar_payment_id' => $payment->id,
                'category_id' => $incomeCategory->id,
                'amount' => $paidInvoice->fiat_amount,
                'fee' => 0,
                'currency' => 'IDR',
                'merchant' => $paidInvoice->customer_name,
                'description' => "Stellar invoice: {$paidInvoice->description}",
                'note' => "Seeded demo income dari invoice {$paidInvoice->payment_memo}",
                'reference_code' => $paidInvoice->payment_memo,
                'happened_at' => $paidInvoice->paid_at,
                'raw_payload' => [
                    'seeded_demo' => true,
                    'transaction_hash' => $transactionHash,
                ],
                'sanitized_description' => "Stellar invoice: {$paidInvoice->description}",
                'categorization_status' => 'categorized',
                'category_source' => 'system',
                'categorized_at' => now()->subHours(6),
            ],
        );

        $payment->update(['transaction_id' => $transaction->id]);
    }
}
