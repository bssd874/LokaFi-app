<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\StellarPayment;
use App\Models\StellarWallet;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\StellarPaymentVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StellarPaymentVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_payment_verification_marks_invoice_paid_and_creates_income_transaction(): void
    {
        $merchant = User::factory()->create();
        $wallet = $this->walletFor($merchant, 10000);
        $invoice = $this->invoiceFor($merchant);
        $hash = $this->hash('a');

        $this->fakeSuccessfulHorizonResponse($invoice, $hash);

        $response = $this->postJson("/api/public/invoices/{$invoice->uuid}/verify-payment", [
            'transaction_hash' => $hash,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.invoice.status', 'paid')
            ->assertJsonPath('data.payment.transaction_hash', $hash)
            ->assertJsonPath('data.payment.network', 'testnet')
            ->assertJsonPath('data.finance_transaction.source', 'stellar');

        $payment = StellarPayment::firstOrFail();
        $transaction = Transaction::firstOrFail();

        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'status' => 'paid',
        ]);
        $this->assertSame($transaction->id, $payment->transaction_id);
        $this->assertSame($invoice->id, $transaction->invoice_id);
        $this->assertSame($payment->id, $transaction->stellar_payment_id);
        $this->assertSame('85000.00', $wallet->fresh()->current_balance);
    }

    public function test_repeating_payment_verification_is_idempotent(): void
    {
        $merchant = User::factory()->create();
        $this->walletFor($merchant);
        $invoice = $this->invoiceFor($merchant);
        $hash = $this->hash('b');

        $this->fakeSuccessfulHorizonResponse($invoice, $hash);

        $this->postJson("/api/public/invoices/{$invoice->uuid}/verify-payment", [
            'transaction_hash' => $hash,
        ])->assertOk();

        $this->postJson("/api/public/invoices/{$invoice->uuid}/verify-payment", [
            'transaction_hash' => $hash,
        ])->assertOk()
            ->assertJsonPath('data.invoice.status', 'paid')
            ->assertJsonPath('data.payment.transaction_hash', $hash);

        $this->assertDatabaseCount('stellar_payments', 1);
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_verified_stellar_payment_updates_dashboard_income_and_balance(): void
    {
        $merchant = User::factory()->create();
        $this->walletFor($merchant, 10000);
        $invoice = $this->invoiceFor($merchant);
        $hash = $this->hash('8');

        $this->fakeSuccessfulHorizonResponse($invoice, $hash);

        $this->postJson("/api/public/invoices/{$invoice->uuid}/verify-payment", [
            'transaction_hash' => $hash,
        ])->assertOk();

        Sanctum::actingAs($merchant);

        $this->getJson('/api/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.summary.total_income', 75000)
            ->assertJsonPath('data.summary.total_balance', 85000)
            ->assertJsonPath('data.summary.transactions_count', 1);
    }

    public function test_transaction_hash_cannot_be_reused_for_another_invoice(): void
    {
        $merchant = User::factory()->create();
        $this->walletFor($merchant);
        $firstInvoice = $this->invoiceFor($merchant);
        $secondInvoice = $this->invoiceFor($merchant, [
            'payment_memo' => 'LOKAFI-SECONDHASH',
        ]);
        $hash = $this->hash('c');

        $this->fakeSuccessfulHorizonResponse($firstInvoice, $hash);

        $this->postJson("/api/public/invoices/{$firstInvoice->uuid}/verify-payment", [
            'transaction_hash' => $hash,
        ])->assertOk();

        $this->postJson("/api/public/invoices/{$secondInvoice->uuid}/verify-payment", [
            'transaction_hash' => $hash,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['transaction_hash']);

        $this->assertDatabaseCount('stellar_payments', 1);
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_verification_rejects_wrong_recipient(): void
    {
        $merchant = User::factory()->create();
        $invoice = $this->invoiceFor($merchant);
        $hash = $this->hash('d');

        $this->fakeSuccessfulHorizonResponse($invoice, $hash, [
            'to' => $this->publicKey('R'),
        ]);

        $this->postJson("/api/public/invoices/{$invoice->uuid}/verify-payment", [
            'transaction_hash' => $hash,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['transaction_hash']);

        $this->assertDatabaseCount('stellar_payments', 0);
        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'status' => 'pending',
        ]);
    }

    public function test_verification_rejects_wrong_amount(): void
    {
        $merchant = User::factory()->create();
        $invoice = $this->invoiceFor($merchant);
        $hash = $this->hash('e');

        $this->fakeSuccessfulHorizonResponse($invoice, $hash, [
            'amount' => '29.0000000',
        ]);

        $this->postJson("/api/public/invoices/{$invoice->uuid}/verify-payment", [
            'transaction_hash' => $hash,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['transaction_hash']);

        $this->assertDatabaseCount('stellar_payments', 0);
    }

    public function test_verification_rejects_wrong_memo(): void
    {
        $merchant = User::factory()->create();
        $invoice = $this->invoiceFor($merchant);
        $hash = $this->hash('f');

        $this->fakeSuccessfulHorizonResponse($invoice, $hash, [], [
            'memo' => 'WRONG-MEMO',
        ]);

        $this->postJson("/api/public/invoices/{$invoice->uuid}/verify-payment", [
            'transaction_hash' => $hash,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['transaction_hash']);

        $this->assertDatabaseCount('stellar_payments', 0);
    }

    public function test_verification_rejects_failed_transaction(): void
    {
        $merchant = User::factory()->create();
        $invoice = $this->invoiceFor($merchant);
        $hash = $this->hash('1');

        $this->fakeSuccessfulHorizonResponse($invoice, $hash, [], [
            'successful' => false,
        ]);

        $this->postJson("/api/public/invoices/{$invoice->uuid}/verify-payment", [
            'transaction_hash' => $hash,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['transaction_hash']);

        $this->assertDatabaseCount('stellar_payments', 0);
    }

    public function test_verification_rejects_non_testnet_transaction_response(): void
    {
        $merchant = User::factory()->create();
        $invoice = $this->invoiceFor($merchant);
        $hash = $this->hash('2');

        $this->fakeSuccessfulHorizonResponse($invoice, $hash, [], [
            'network_passphrase' => 'Public Global Stellar Network ; September 2015',
        ]);

        $this->postJson("/api/public/invoices/{$invoice->uuid}/verify-payment", [
            'transaction_hash' => $hash,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['transaction_hash']);

        $this->assertDatabaseCount('stellar_payments', 0);
    }

    public function test_expired_invoice_cannot_be_verified(): void
    {
        Http::preventStrayRequests();

        $merchant = User::factory()->create();
        $invoice = $this->invoiceFor($merchant, [
            'expires_at' => now()->subMinute(),
        ]);

        $this->postJson("/api/public/invoices/{$invoice->uuid}/verify-payment", [
            'transaction_hash' => $this->hash('3'),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['invoice']);

        $this->assertDatabaseCount('stellar_payments', 0);
        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'status' => 'expired',
        ]);
    }

    public function test_authenticated_verify_endpoint_enforces_invoice_ownership(): void
    {
        Http::preventStrayRequests();

        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $invoice = $this->invoiceFor($owner);

        Sanctum::actingAs($otherUser);

        $this->postJson("/api/invoices/{$invoice->id}/verify-payment", [
            'transaction_hash' => $this->hash('4'),
        ])->assertForbidden();
    }

    public function test_authenticated_user_can_list_only_their_stellar_payments(): void
    {
        $merchant = User::factory()->create();
        $otherMerchant = User::factory()->create();
        $this->walletFor($merchant);
        $this->walletFor($otherMerchant);
        $invoice = $this->invoiceFor($merchant);
        $otherInvoice = $this->invoiceFor($otherMerchant, [
            'payment_memo' => 'LOKAFI-OTHERPAY01',
        ]);
        $hash = $this->hash('6');
        $otherHash = $this->hash('7');

        $this->fakeSuccessfulHorizonResponse($invoice, $hash);
        $this->postJson("/api/public/invoices/{$invoice->uuid}/verify-payment", [
            'transaction_hash' => $hash,
        ])->assertOk();

        $this->fakeSuccessfulHorizonResponse($otherInvoice, $otherHash);
        $this->postJson("/api/public/invoices/{$otherInvoice->uuid}/verify-payment", [
            'transaction_hash' => $otherHash,
        ])->assertOk();

        Sanctum::actingAs($merchant);

        $this->getJson('/api/stellar/payments')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.transaction_hash', $hash)
            ->assertJsonPath('data.0.invoice.id', $invoice->id);
    }

    public function test_request_rejects_frontend_success_claims(): void
    {
        Http::preventStrayRequests();

        $merchant = User::factory()->create();
        $invoice = $this->invoiceFor($merchant);

        $this->postJson("/api/public/invoices/{$invoice->uuid}/verify-payment", [
            'transaction_hash' => $this->hash('5'),
            'successful' => true,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['successful']);
    }

    private function fakeSuccessfulHorizonResponse(
        Invoice $invoice,
        string $hash,
        array $operationOverrides = [],
        array $transactionOverrides = [],
    ): void {
        Http::preventStrayRequests();

        $transactionPayload = array_merge([
            'id' => $hash,
            'hash' => $hash,
            'successful' => true,
            'ledger' => 123456,
            'memo_type' => 'text',
            'memo' => $invoice->payment_memo,
            'created_at' => now()->toIso8601String(),
            'source_account' => $this->publicKey('S'),
            'network_passphrase' => StellarPaymentVerificationService::TESTNET_PASSPHRASE,
        ], $transactionOverrides);

        $operationPayload = array_merge([
            'id' => '123456789',
            'type' => 'payment',
            'from' => $this->publicKey('S'),
            'to' => $invoice->recipient_public_key,
            'asset_type' => 'native',
            'amount' => $invoice->stellar_amount,
        ], $operationOverrides);

        Http::fake([
            StellarPaymentVerificationService::HORIZON_TESTNET_URL . "/transactions/{$hash}" => Http::response($transactionPayload),
            StellarPaymentVerificationService::HORIZON_TESTNET_URL . "/transactions/{$hash}/operations*" => Http::response([
                '_embedded' => [
                    'records' => [$operationPayload],
                ],
            ]),
        ]);
    }

    private function invoiceFor(User $user, array $overrides = []): Invoice
    {
        $publicKey = $overrides['recipient_public_key'] ?? $this->connectWallet($user, 'M');

        return Invoice::create(array_merge([
            'uuid' => (string) fake()->uuid(),
            'user_id' => $user->id,
            'customer_name' => 'Customer Demo',
            'customer_email' => 'customer@example.com',
            'description' => 'Invoice Stellar fixture',
            'fiat_currency' => 'IDR',
            'fiat_amount' => 75000,
            'demo_exchange_rate' => 2500,
            'stellar_asset_code' => 'XLM',
            'stellar_amount' => '30.0000000',
            'recipient_public_key' => $publicKey,
            'payment_memo' => 'LOKAFI-' . strtoupper(fake()->bothify('????????????')),
            'status' => 'pending',
            'expires_at' => now()->addDay(),
            'paid_at' => null,
        ], $overrides));
    }

    private function walletFor(User $user, int $balance = 0): Wallet
    {
        return $user->wallets()->create([
            'name' => 'Cash',
            'type' => 'cash',
            'currency' => 'IDR',
            'opening_balance' => $balance,
            'current_balance' => $balance,
            'is_active' => true,
        ]);
    }

    private function connectWallet(User $user, string $character): string
    {
        $publicKey = $this->publicKey($character);

        StellarWallet::updateOrCreate(
            [
                'user_id' => $user->id,
                'network' => 'testnet',
                'wallet_provider' => 'freighter',
            ],
            [
                'public_key' => $publicKey,
                'connected_at' => now(),
            ],
        );

        return $publicKey;
    }

    private function publicKey(string $character): string
    {
        return 'G' . str_repeat($character, 55);
    }

    private function hash(string $character): string
    {
        return str_repeat($character, 64);
    }
}
