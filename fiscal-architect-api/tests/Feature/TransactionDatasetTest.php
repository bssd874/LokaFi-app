<?php

namespace Tests\Feature;

use App\Models\BankConnection;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\TransactionCategoryLabel;
use App\Models\User;
use App\Models\Wallet;
use App\Services\BrankasTransactionImportService;
use App\Services\TransactionSanitizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TransactionDatasetTest extends TestCase
{
    use RefreshDatabase;

    public function test_sanitization_redacts_sensitive_values_but_keeps_classification_terms(): void
    {
        $user = User::factory()->create(['name' => 'Boni Steven']);
        $service = app(TransactionSanitizationService::class);

        $result = $service->sanitizeText(
            'QRIS WARUNG MAKAN payroll Boni Steven rek 1234567890 card 4111 1111 1111 1111 otp 123456 email boni@example.com token abc123',
            $user,
        );

        $this->assertStringContainsString('QRIS WARUNG MAKAN payroll', $result);
        $this->assertStringNotContainsString('Boni Steven', $result);
        $this->assertStringNotContainsString('1234567890', $result);
        $this->assertStringNotContainsString('4111 1111 1111 1111', $result);
        $this->assertStringNotContainsString('boni@example.com', $result);
        $this->assertStringNotContainsString('abc123', $result);
    }

    public function test_brankas_import_saves_sanitized_unclassified_transactions_and_prevents_duplicates(): void
    {
        [$user, $wallet, $connection] = $this->bankFixture();

        $service = app(BrankasTransactionImportService::class);
        $transactions = [
            [
                'external_transaction_id' => 'BRANKAS-001',
                'type' => 'expense',
                'amount' => 45000,
                'currency' => 'IDR',
                'merchant' => 'QRIS WARUNG MAKAN',
                'note' => 'QRIS WARUNG MAKAN rek 1234567890 otp 123456',
                'happened_at' => '2026-07-13 09:00:00',
                'raw_payload' => [
                    'descriptor' => 'QRIS WARUNG MAKAN rek 1234567890',
                    'token' => 'secret-token',
                ],
            ],
            [
                'type' => 'expense',
                'amount' => 12000,
                'merchant' => 'Invalid Date',
            ],
        ];

        $first = $service->import($connection, $wallet, $transactions);
        $second = $service->import($connection, $wallet, $transactions);

        $this->assertSame(1, $first['imported_count']);
        $this->assertSame(1, $first['error_count']);
        $this->assertSame(0, $second['imported_count']);
        $this->assertSame(1, $second['skipped_count']);

        $transaction = Transaction::firstOrFail();
        $this->assertSame($user->id, $transaction->user_id);
        $this->assertSame($wallet->id, $transaction->wallet_id);
        $this->assertSame($connection->id, $transaction->bank_connection_id);
        $this->assertSame('BRANKAS-001', $transaction->external_transaction_id);
        $this->assertSame('brankas', $transaction->source);
        $this->assertNull($transaction->category_id);
        $this->assertSame('unclassified', $transaction->categorization_status);
        $this->assertStringNotContainsString('1234567890', $transaction->sanitized_description);
        $this->assertSame('[redacted]', $transaction->raw_payload['token']);
    }

    public function test_update_transaction_category_creates_verified_label(): void
    {
        [$user, $wallet] = $this->manualFixture();
        $category = Category::create([
            'user_id' => $user->id,
            'name' => 'Makanan dan Minuman',
            'type' => 'expense',
            'icon' => 'tag',
            'color' => '#EF4444',
        ]);
        $transaction = $this->transaction($user, $wallet, [
            'source' => 'brankas',
            'description' => 'QRIS WARUNG MAKAN 081234567890',
            'sanitized_description' => 'QRIS WARUNG MAKAN [phone]',
        ]);

        Sanctum::actingAs($user);

        $response = $this->patchJson("/api/transactions/{$transaction->id}/category", [
            'category_id' => $category->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.categorization_status', 'categorized')
            ->assertJsonPath('data.category_source', 'user');

        $this->assertDatabaseHas('transaction_category_labels', [
            'transaction_id' => $transaction->id,
            'category_id' => $category->id,
            'labeled_by' => 'user',
            'is_verified' => true,
        ]);
    }

    public function test_bulk_category_only_updates_current_users_transactions(): void
    {
        [$user, $wallet] = $this->manualFixture();
        [$otherUser, $otherWallet] = $this->manualFixture();
        $category = Category::create([
            'user_id' => $user->id,
            'name' => 'Transportasi',
            'type' => 'expense',
            'icon' => 'car',
            'color' => '#3B82F6',
        ]);

        $first = $this->transaction($user, $wallet);
        $second = $this->transaction($user, $wallet, ['description' => 'TAXI ONLINE']);
        $other = $this->transaction($otherUser, $otherWallet);

        Sanctum::actingAs($user);

        $this->postJson('/api/transactions/bulk-category', [
            'transaction_ids' => [$first->id, $other->id],
            'category_id' => $category->id,
        ])->assertForbidden();

        $this->postJson('/api/transactions/bulk-category', [
            'transaction_ids' => [$first->id, $second->id],
            'category_id' => $category->id,
        ])->assertOk()
            ->assertJsonPath('data.updated_count', 2);

        $this->assertDatabaseHas('transactions', [
            'id' => $first->id,
            'category_id' => $category->id,
            'categorization_status' => 'categorized',
        ]);
        $this->assertDatabaseMissing('transaction_category_labels', [
            'transaction_id' => $other->id,
            'category_id' => $category->id,
        ]);
    }

    public function test_dataset_summary_and_csv_export_use_verified_sanitized_labels_only(): void
    {
        [$user, $wallet] = $this->manualFixture();
        $category = Category::create([
            'user_id' => $user->id,
            'name' => 'Makanan',
            'type' => 'expense',
            'icon' => 'tag',
            'color' => '#EF4444',
        ]);
        $verified = $this->transaction($user, $wallet, [
            'category_id' => $category->id,
            'sanitized_description' => 'QRIS WARUNG MAKAN [phone]',
            'categorization_status' => 'categorized',
            'category_source' => 'user',
            'categorized_at' => now(),
        ]);
        $unverified = $this->transaction($user, $wallet, [
            'description' => 'TOKEN abc123 SHOULD NOT EXPORT',
            'sanitized_description' => 'TOKEN [redacted] SHOULD NOT EXPORT',
        ]);

        TransactionCategoryLabel::create([
            'user_id' => $user->id,
            'transaction_id' => $verified->id,
            'category_id' => $category->id,
            'sanitized_description' => $verified->sanitized_description,
            'transaction_type' => 'expense',
            'amount' => $verified->amount,
            'source' => 'brankas',
            'labeled_by' => 'user',
            'is_verified' => true,
        ]);

        TransactionCategoryLabel::create([
            'user_id' => $user->id,
            'transaction_id' => $unverified->id,
            'category_id' => $category->id,
            'sanitized_description' => $unverified->sanitized_description,
            'transaction_type' => 'expense',
            'amount' => $unverified->amount,
            'source' => 'brankas',
            'labeled_by' => 'user',
            'is_verified' => false,
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/transaction-dataset/summary')
            ->assertOk()
            ->assertJsonPath('data.total_transactions', 2)
            ->assertJsonPath('data.total_labeled', 2)
            ->assertJsonPath('data.total_verified', 1);

        $csv = $this->get('/api/transaction-dataset/export')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('description,label,type,amount,source', $csv);
        $this->assertStringContainsString('QRIS WARUNG MAKAN [phone]', $csv);
        $this->assertStringNotContainsString('abc123', $csv);
        $this->assertStringNotContainsString('TOKEN', $csv);
    }

    public function test_dataset_export_command_writes_csv_and_reports_skipped_unverified(): void
    {
        [$user, $wallet] = $this->manualFixture();
        $category = Category::create([
            'user_id' => $user->id,
            'name' => 'Pemasukan',
            'type' => 'income',
            'icon' => 'wallet',
            'color' => '#22C55E',
        ]);
        $transaction = $this->transaction($user, $wallet, [
            'type' => 'income',
            'category_id' => $category->id,
            'amount' => 4500000,
            'sanitized_description' => 'TRANSFER PAYROLL PERUSAHAAN',
            'categorization_status' => 'categorized',
            'category_source' => 'user',
            'categorized_at' => now(),
        ]);

        TransactionCategoryLabel::create([
            'user_id' => $user->id,
            'transaction_id' => $transaction->id,
            'category_id' => $category->id,
            'sanitized_description' => $transaction->sanitized_description,
            'transaction_type' => 'income',
            'amount' => $transaction->amount,
            'source' => 'brankas',
            'labeled_by' => 'user',
            'is_verified' => true,
        ]);

        $output = storage_path('app/datasets/test_transactions.csv');

        $this->artisan('dataset:export-transactions', [
            '--user' => $user->id,
            '--verified-only' => true,
            '--output' => $output,
            '--force' => true,
        ])->expectsOutputToContain('Data diekspor: 1')
            ->assertSuccessful();

        $this->assertFileExists($output);
        $this->assertStringContainsString('TRANSFER PAYROLL PERUSAHAAN', file_get_contents($output));
    }

    private function bankFixture(): array
    {
        $user = User::factory()->create();
        $connection = BankConnection::create([
            'user_id' => $user->id,
            'provider_code' => 'bca',
            'provider_name' => 'Bank Central Asia',
            'status' => 'connected',
            'mode' => 'brankas',
            'account_number_masked' => '****1234',
        ]);
        $wallet = Wallet::create([
            'user_id' => $user->id,
            'name' => 'BCA ****1234',
            'type' => 'bank',
            'currency' => 'IDR',
            'opening_balance' => 0,
            'current_balance' => 0,
            'is_active' => true,
            'bank_connection_id' => $connection->id,
        ]);

        return [$user, $wallet, $connection];
    }

    private function manualFixture(): array
    {
        $user = User::factory()->create();
        $wallet = Wallet::create([
            'user_id' => $user->id,
            'name' => 'Cash',
            'type' => 'cash',
            'currency' => 'IDR',
            'opening_balance' => 0,
            'current_balance' => 0,
            'is_active' => true,
        ]);

        return [$user, $wallet];
    }

    private function transaction(User $user, Wallet $wallet, array $overrides = []): Transaction
    {
        return Transaction::create(array_merge([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'type' => 'expense',
            'amount' => 45000,
            'fee' => 0,
            'currency' => 'IDR',
            'merchant' => 'QRIS WARUNG',
            'description' => 'QRIS WARUNG MAKAN',
            'note' => 'QRIS WARUNG MAKAN',
            'happened_at' => '2026-07-13 09:00:00',
            'source' => 'brankas',
            'external_transaction_id' => uniqid('trx-', true),
            'sanitized_description' => 'QRIS WARUNG MAKAN',
            'categorization_status' => 'unclassified',
            'category_source' => 'unclassified',
        ], $overrides));
    }
}
