<?php

namespace Tests\Feature;

use App\Models\NormalizedTransactionImportRow;
use App\Models\Transaction;
use App\Models\TransactionImportBatch;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TransactionImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_csv_preview_creates_batch_rows_and_prevents_duplicate_file_batches(): void
    {
        [$user, $wallet] = $this->fixture();
        Sanctum::actingAs($user);

        $file = $this->csvFile(implode("\n", [
            'date,description,amount,type,external_id',
            '2026-07-01,QRIS WARUNG MAKAN otp 123456,45000,expense,CSV-001',
            '2026-07-02,TRANSFER PAYROLL,2500000,income,CSV-002',
        ]));

        $response = $this->post('/api/transaction-imports/preview', [
            'source_type' => TransactionImportBatch::SOURCE_BANK_CSV,
            'wallet_id' => $wallet->id,
            'file' => $file,
        ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('data.batch.total_rows', 2)
            ->assertJsonPath('data.duplicate_file', false)
            ->assertJsonPath('data.batch.column_mapping.happened_at', 'date')
            ->assertJsonPath('data.batch.column_mapping.external_transaction_id', 'external_id');

        $this->assertDatabaseCount('transaction_import_batches', 1);
        $this->assertDatabaseCount('normalized_transaction_import_rows', 2);

        $row = NormalizedTransactionImportRow::where('row_number', 2)->firstOrFail();
        $this->assertStringNotContainsString('123456', $row->raw_payload['description']);

        $duplicateResponse = $this->post('/api/transaction-imports/preview', [
            'source_type' => TransactionImportBatch::SOURCE_BANK_CSV,
            'wallet_id' => $wallet->id,
            'file' => $this->csvFile(implode("\n", [
                'date,description,amount,type,external_id',
                '2026-07-01,QRIS WARUNG MAKAN otp 123456,45000,expense,CSV-001',
                '2026-07-02,TRANSFER PAYROLL,2500000,income,CSV-002',
            ])),
        ], ['Accept' => 'application/json']);

        $duplicateResponse->assertOk()
            ->assertJsonPath('data.duplicate_file', true)
            ->assertJsonPath('data.batch.total_rows', 2);

        $this->assertDatabaseCount('transaction_import_batches', 1);
    }

    public function test_csv_commit_imports_transactions_and_marks_duplicate_rows(): void
    {
        [$user, $wallet] = $this->fixture(currentBalance: 100000);
        Sanctum::actingAs($user);

        $preview = $this->post('/api/transaction-imports/preview', [
            'source_type' => TransactionImportBatch::SOURCE_EWALLET_CSV,
            'wallet_id' => $wallet->id,
            'file' => $this->csvFile(implode("\n", [
                'tanggal,keterangan,nominal,jenis,ref',
                '2026-07-01 10:00:00,QRIS KOPI SUSU,25000,expense,',
                '2026-07-01 10:00:00,QRIS KOPI SUSU,25000,expense,',
                '2026-07-02 09:00:00,CASHBACK PROMO,5000,income,CASHBACK-001',
                'not-a-date,BROKEN ROW,1000,expense,BROKEN-001',
            ])),
        ], ['Accept' => 'application/json'])->assertCreated();

        $batchId = $preview->json('data.batch.id');

        $response = $this->postJson('/api/transaction-imports/commit', [
            'batch_id' => $batchId,
            'mapping' => [
                'happened_at' => 'tanggal',
                'description' => 'keterangan',
                'amount' => 'nominal',
                'type' => 'jenis',
                'reference_code' => 'ref',
                'external_transaction_id' => 'ref',
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.summary.imported_count', 2)
            ->assertJsonPath('data.summary.duplicate_count', 1)
            ->assertJsonPath('data.summary.invalid_count', 1)
            ->assertJsonPath('data.summary.failed_count', 0);

        $this->assertDatabaseCount('transactions', 2);
        $this->assertDatabaseHas('transactions', [
            'source' => TransactionImportBatch::SOURCE_EWALLET_CSV,
            'categorization_status' => 'categorized',
            'category_source' => 'default_rule',
        ]);

        $this->assertSame('80000.00', $wallet->fresh()->current_balance);

        $statuses = NormalizedTransactionImportRow::orderBy('row_number')->pluck('status')->all();
        $this->assertSame([
            NormalizedTransactionImportRow::STATUS_IMPORTED,
            NormalizedTransactionImportRow::STATUS_DUPLICATE,
            NormalizedTransactionImportRow::STATUS_IMPORTED,
            NormalizedTransactionImportRow::STATUS_INVALID,
        ], $statuses);

        $transaction = Transaction::where('source', TransactionImportBatch::SOURCE_EWALLET_CSV)->firstOrFail();
        $this->assertNotNull($transaction->import_batch_id);
        $this->assertNotNull($transaction->import_row_id);
        $this->assertNotNull($transaction->dedupe_fingerprint);
        $this->assertSame('kopi susu', $transaction->normalized_description);

        $secondCommit = $this->postJson('/api/transaction-imports/commit', [
            'batch_id' => $batchId,
            'mapping' => [
                'happened_at' => 'tanggal',
                'description' => 'keterangan',
                'amount' => 'nominal',
                'type' => 'jenis',
            ],
        ]);

        $secondCommit->assertOk()
            ->assertJsonPath('data.idempotent', true)
            ->assertJsonPath('data.summary.imported_count', 2);

        $this->assertDatabaseCount('transactions', 2);
        $this->assertSame('80000.00', $wallet->fresh()->current_balance);
    }

    public function test_csv_commit_reuses_existing_external_transaction_id_as_duplicate(): void
    {
        [$user, $wallet] = $this->fixture();
        Sanctum::actingAs($user);

        Transaction::create([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'type' => 'expense',
            'amount' => 12000,
            'fee' => 0,
            'currency' => 'IDR',
            'description' => 'Existing row',
            'note' => 'Existing row',
            'happened_at' => '2026-07-01 10:00:00',
            'source' => TransactionImportBatch::SOURCE_BANK_CSV,
            'external_transaction_id' => 'EXT-001',
            'dedupe_fingerprint' => hash('sha256', implode('|', [
                $user->id,
                TransactionImportBatch::SOURCE_BANK_CSV,
                'ext-001',
            ])),
            'sanitized_description' => 'Existing row',
            'categorization_status' => 'unclassified',
            'category_source' => 'unclassified',
        ]);

        $preview = $this->post('/api/transaction-imports/preview', [
            'source_type' => TransactionImportBatch::SOURCE_BANK_CSV,
            'wallet_id' => $wallet->id,
            'file' => $this->csvFile(implode("\n", [
                'date,description,amount,type,external_id',
                '2026-07-03,QRIS DUPLICATE,99000,expense,EXT-001',
            ])),
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->postJson('/api/transaction-imports/commit', [
            'batch_id' => $preview->json('data.batch.id'),
            'mapping' => [
                'happened_at' => 'date',
                'description' => 'description',
                'amount' => 'amount',
                'type' => 'type',
                'external_transaction_id' => 'external_id',
            ],
        ])->assertOk()
            ->assertJsonPath('data.summary.imported_count', 0)
            ->assertJsonPath('data.summary.duplicate_count', 1);

        $this->assertDatabaseCount('transactions', 1);
    }

    private function fixture(int $currentBalance = 0): array
    {
        $user = User::factory()->create();
        $wallet = Wallet::create([
            'user_id' => $user->id,
            'name' => 'Statement Wallet',
            'type' => 'ewallet',
            'currency' => 'IDR',
            'opening_balance' => $currentBalance,
            'current_balance' => $currentBalance,
            'is_active' => true,
        ]);

        return [$user, $wallet];
    }

    private function csvFile(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('statement.csv', $content);
    }
}
