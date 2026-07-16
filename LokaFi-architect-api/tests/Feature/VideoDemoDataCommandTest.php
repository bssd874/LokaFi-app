<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\StellarPayment;
use App\Models\Transaction;
use App\Models\TransactionImportBatch;
use App\Models\User;
use App\Models\Wallet;
use App\Services\FinancialIntelligenceService;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VideoDemoDataCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_prepares_isolated_deterministic_data_without_fake_stellar_payments(): void
    {
        $this->artisan('lokafi:demo:prepare', [
            '--reset' => true,
            '--reference-date' => '2026-07-15',
        ])->assertSuccessful();

        $user = User::where('email', 'demo.video@lokafi.local')->firstOrFail();
        $transactions = Transaction::where('user_id', $user->id)->get();

        $this->assertSame('Boni Steven Demo', $user->name);
        $this->assertCount(32, $transactions);
        $this->assertSame(32, $transactions->pluck('external_transaction_id')->unique()->count());
        $this->assertSame(32, $transactions->pluck('dedupe_fingerprint')->unique()->count());
        $this->assertTrue($transactions->every(
            fn (Transaction $transaction) => str_starts_with((string) $transaction->external_transaction_id, 'VIDEO-DEMO-MANUAL-')
                && $transaction->raw_payload['demo_dataset'] === 'lokafi_video_demo_v1'
                && $transaction->raw_payload['demo_run_id'] === 'LOKAFI-VIDEO-DEMO-2026',
        ));

        $this->assertSame(3, Wallet::where('user_id', $user->id)->count());
        $this->assertSame(4, Budget::where('user_id', $user->id)->count());
        $this->assertSame(0, StellarPayment::count());
        $this->assertSame(0, Transaction::where('user_id', $user->id)->where('source', 'stellar')->count());

        $summary = app(FinancialIntelligenceService::class)->summary($user, [
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-31',
            'timezone' => 'Asia/Jakarta',
        ]);

        $this->assertSame(7500000.0, $summary['summary']['total_income']);
        $this->assertSame(2460000.0, $summary['summary']['total_expense']);
        $this->assertSame(5040000.0, $summary['summary']['net_cashflow']);
        $this->assertFiniteValues($summary);
    }

    public function test_command_is_idempotent_and_reset_keeps_non_demo_users_safe(): void
    {
        $otherUser = User::factory()->create(['email' => 'owner@example.test']);

        $this->artisan('lokafi:demo:prepare', ['--reset' => true])->assertSuccessful();
        $demoUser = User::where('email', 'demo.video@lokafi.local')->firstOrFail();
        $firstDemoUserId = $demoUser->id;

        $this->artisan('lokafi:demo:prepare')
            ->expectsOutput('Video demo data already exists for this email. Re-run with --reset to recreate it.')
            ->assertSuccessful();

        $this->assertSame(32, Transaction::where('user_id', $firstDemoUserId)->count());

        $this->artisan('lokafi:demo:prepare', ['--reset' => true])->assertSuccessful();
        $recreatedDemoUser = User::where('email', 'demo.video@lokafi.local')->firstOrFail();

        $this->assertNotSame($firstDemoUserId, $recreatedDemoUser->id);
        $this->assertSame(32, Transaction::where('user_id', $recreatedDemoUser->id)->count());
        $this->assertDatabaseHas('users', ['id' => $otherUser->id, 'email' => 'owner@example.test']);
    }

    public function test_command_rejects_an_existing_non_demo_email(): void
    {
        User::factory()->create([
            'name' => 'Real Account Owner',
            'email' => 'existing@example.test',
        ]);

        $this->artisan('lokafi:demo:prepare', [
            '--email' => 'existing@example.test',
            '--reset' => true,
        ])->assertExitCode(Command::FAILURE);

        $this->assertDatabaseHas('users', [
            'name' => 'Real Account Owner',
            'email' => 'existing@example.test',
        ]);
    }

    public function test_command_is_blocked_outside_local_and_testing(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('lokafi:demo:prepare', ['--reset' => true])
            ->expectsOutput('Video demo preparation is disabled outside local/testing environments.')
            ->assertExitCode(Command::FAILURE);

        $this->assertDatabaseMissing('users', ['email' => 'demo.video@lokafi.local']);
    }

    public function test_packaged_csv_files_produce_expected_import_and_duplicate_results(): void
    {
        $this->artisan('lokafi:demo:prepare', [
            '--reset' => true,
            '--reference-date' => '2026-07-15',
        ])->assertSuccessful();

        $user = User::where('email', 'demo.video@lokafi.local')->firstOrFail();
        $bankWallet = Wallet::where('user_id', $user->id)->where('name', 'BCA Statement Import')->firstOrFail();
        $ewallet = Wallet::where('user_id', $user->id)->where('name', 'GoPay Statement Import')->firstOrFail();
        Sanctum::actingAs($user);

        $bankBatch = $this->preview(
            source: TransactionImportBatch::SOURCE_BANK_CSV,
            wallet: $bankWallet,
            filename: 'bank_statement_video_demo.csv',
        );
        $this->commit($bankBatch, [
            'happened_at' => 'transaction_date',
            'description' => 'description',
            'type' => 'transaction_type',
            'amount' => 'amount',
            'external_transaction_id' => 'external_id',
        ])->assertJsonPath('data.summary.imported_count', 12)
            ->assertJsonPath('data.summary.duplicate_count', 0)
            ->assertJsonPath('data.summary.invalid_count', 0)
            ->assertJsonPath('data.summary.failed_count', 0);

        $ewalletBatch = $this->preview(
            source: TransactionImportBatch::SOURCE_EWALLET_CSV,
            wallet: $ewallet,
            filename: 'ewallet_statement_video_demo.csv',
        );
        $this->commit($ewalletBatch, [
            'happened_at' => 'date_time',
            'merchant' => 'merchant',
            'type' => 'direction',
            'amount' => 'total',
            'reference_code' => 'reference',
            'external_transaction_id' => 'reference',
        ])->assertJsonPath('data.summary.imported_count', 10)
            ->assertJsonPath('data.summary.duplicate_count', 0)
            ->assertJsonPath('data.summary.invalid_count', 0)
            ->assertJsonPath('data.summary.failed_count', 0);

        $duplicateBatch = $this->preview(
            source: TransactionImportBatch::SOURCE_BANK_CSV,
            wallet: $bankWallet,
            filename: 'bank_statement_duplicate_demo.csv',
        );
        $this->commit($duplicateBatch, [
            'happened_at' => 'transaction_date',
            'description' => 'description',
            'type' => 'transaction_type',
            'amount' => 'amount',
            'external_transaction_id' => 'external_id',
        ])->assertJsonPath('data.summary.imported_count', 1)
            ->assertJsonPath('data.summary.duplicate_count', 1)
            ->assertJsonPath('data.summary.invalid_count', 0)
            ->assertJsonPath('data.summary.failed_count', 0);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'external_transaction_id' => 'VIDEO-DEMO-BANK-0004',
            'category_source' => 'user_rule',
        ]);
        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'external_transaction_id' => 'VIDEO-DEMO-BANK-0010',
            'category_source' => 'verified_mapping',
        ]);
        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'external_transaction_id' => 'VIDEO-DEMO-BANK-0007',
            'category_id' => null,
            'categorization_status' => 'review_required',
        ]);

        $summary = app(FinancialIntelligenceService::class)->summary($user->fresh(), [
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-31',
            'timezone' => 'Asia/Jakarta',
        ]);

        $this->assertSame(10700000.0, $summary['summary']['total_income']);
        $this->assertSame(6942000.0, $summary['summary']['total_expense']);
        $this->assertSame(3758000.0, $summary['summary']['net_cashflow']);
        $this->assertSame(37, $summary['summary']['transaction_count']);
        $this->assertFiniteValues($summary);
    }

    public function test_packaged_invalid_csv_imports_only_the_valid_row(): void
    {
        $this->artisan('lokafi:demo:prepare', [
            '--reset' => true,
            '--reference-date' => '2026-07-15',
        ])->assertSuccessful();

        $user = User::where('email', 'demo.video@lokafi.local')->firstOrFail();
        $bankWallet = Wallet::where('user_id', $user->id)
            ->where('name', 'BCA Statement Import')
            ->firstOrFail();
        Sanctum::actingAs($user);

        $batch = $this->preview(
            source: TransactionImportBatch::SOURCE_BANK_CSV,
            wallet: $bankWallet,
            filename: 'bank_statement_invalid_demo.csv',
        );

        $this->commit($batch, [
            'happened_at' => 'transaction_date',
            'description' => 'description',
            'type' => 'transaction_type',
            'amount' => 'amount',
            'external_transaction_id' => 'external_id',
        ])->assertJsonPath('data.summary.imported_count', 1)
            ->assertJsonPath('data.summary.duplicate_count', 0)
            ->assertJsonPath('data.summary.invalid_count', 3)
            ->assertJsonPath('data.summary.failed_count', 0);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'external_transaction_id' => 'VIDEO-DEMO-BANK-INVALID-0001',
        ]);
        $this->assertDatabaseMissing('transactions', [
            'user_id' => $user->id,
            'external_transaction_id' => 'VIDEO-DEMO-BANK-INVALID-0002',
        ]);
        $this->assertDatabaseMissing('transactions', [
            'user_id' => $user->id,
            'external_transaction_id' => 'VIDEO-DEMO-BANK-INVALID-0003',
        ]);
        $this->assertDatabaseMissing('transactions', [
            'user_id' => $user->id,
            'external_transaction_id' => 'VIDEO-DEMO-BANK-INVALID-0004',
        ]);
    }

    private function preview(string $source, Wallet $wallet, string $filename): int
    {
        $response = $this->post('/api/transaction-imports/preview', [
            'source_type' => $source,
            'wallet_id' => $wallet->id,
            'file' => $this->demoCsv($filename),
        ], ['Accept' => 'application/json'])->assertCreated();

        return (int) $response->json('data.batch.id');
    }

    private function commit(int $batchId, array $mapping): TestResponse
    {
        return $this->postJson('/api/transaction-imports/commit', [
            'batch_id' => $batchId,
            'mapping' => $mapping,
        ])->assertOk();
    }

    private function demoCsv(string $filename): UploadedFile
    {
        $path = base_path('../demo-data/video-demo/'.$filename);

        $this->assertFileExists($path);

        return new UploadedFile($path, $filename, 'text/csv', null, true);
    }

    private function assertFiniteValues(mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->assertFiniteValues($item);
            }

            return;
        }

        if (is_float($value)) {
            $this->assertTrue(is_finite($value), 'Financial analytics must not contain NaN or Infinity.');
        }
    }
}
