<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_defaults_to_actual_last_30_days_and_uses_one_period_for_every_section(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-15 12:00:00', 'Asia/Jakarta'));

        [$user, $wallet, $expenseCategory, $incomeCategory] = $this->fixture();
        Sanctum::actingAs($user);

        $this->transaction($user, $wallet, $incomeCategory, [
            'type' => 'income',
            'amount' => 2500000,
            'happened_at' => '2026-07-01 09:00:00',
        ]);
        $this->transaction($user, $wallet, $expenseCategory, [
            'amount' => 125000,
            'fee' => 5000,
            'source' => 'bank_csv',
            'happened_at' => '2026-07-10 18:30:00',
        ]);
        $this->transaction($user, $wallet, $expenseCategory, [
            'amount' => 999999,
            'happened_at' => '2026-06-15 10:00:00',
        ]);
        $this->transaction($user, $wallet, $expenseCategory, [
            'amount' => 999999,
            'happened_at' => '2026-07-16 00:00:00',
        ]);

        $response = $this->getJson('/api/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.period.start_date', '2026-06-16')
            ->assertJsonPath('data.period.end_date', '2026-07-15')
            ->assertJsonPath('data.period.timezone', 'Asia/Jakarta')
            ->assertJsonPath('data.summary.total_income', 2500000)
            ->assertJsonPath('data.summary.total_expense', 130000)
            ->assertJsonPath('data.summary.net_cashflow', 2370000)
            ->assertJsonPath('data.summary.transactions_count', 2);

        $data = $response->json('data');

        $this->assertCount(30, $data['daily_cashflow']);
        $this->assertSame('2026-06-16', $data['daily_cashflow'][0]['date']);
        $this->assertSame('2026-07-15', $data['daily_cashflow'][29]['date']);

        $julyExpense = collect($data['daily_cashflow'])->firstWhere('date', '2026-07-10');
        $this->assertEquals(130000, $julyExpense['expense']);
        $this->assertCount(2, $data['recent_transactions']);
        $this->assertEquals(
            ['2026-07-10', '2026-07-01'],
            collect($data['recent_transactions'])
                ->pluck('happened_at')
                ->map(fn (string $date) => CarbonImmutable::parse($date)->toDateString())
                ->all(),
        );
    }

    public function test_dashboard_comparison_uses_previous_equal_length_period(): void
    {
        [$user, $wallet, $expenseCategory, $incomeCategory] = $this->fixture();
        Sanctum::actingAs($user);

        $this->transaction($user, $wallet, $incomeCategory, [
            'type' => 'income',
            'amount' => 200000,
            'happened_at' => '2026-07-02 09:00:00',
        ]);
        $this->transaction($user, $wallet, $expenseCategory, [
            'amount' => 100000,
            'happened_at' => '2026-07-04 09:00:00',
        ]);
        $this->transaction($user, $wallet, $incomeCategory, [
            'type' => 'income',
            'amount' => 100000,
            'happened_at' => '2026-06-21 09:00:00',
        ]);
        $this->transaction($user, $wallet, $expenseCategory, [
            'amount' => 50000,
            'happened_at' => '2026-06-30 23:59:59',
        ]);

        $this->getJson('/api/dashboard/summary?start_date=2026-07-01&end_date=2026-07-10')
            ->assertOk()
            ->assertJsonPath('data.comparison.previous_income', 100000)
            ->assertJsonPath('data.comparison.previous_expense', 50000)
            ->assertJsonPath('data.comparison.previous_net_cashflow', 50000)
            ->assertJsonPath('data.comparison.income_change_percentage', 100)
            ->assertJsonPath('data.comparison.expense_change_percentage', 100)
            ->assertJsonPath('data.comparison.net_cashflow_change_percentage', 100)
            ->assertJsonPath('data.comparison.status', 'available');
    }

    public function test_dashboard_zero_previous_baseline_returns_null_comparison_without_nan_or_infinity(): void
    {
        [$user, $wallet, $expenseCategory] = $this->fixture();
        Sanctum::actingAs($user);

        $this->transaction($user, $wallet, $expenseCategory, [
            'amount' => 100000,
            'happened_at' => '2026-07-03 09:00:00',
        ]);

        $response = $this->getJson('/api/dashboard/summary?start_date=2026-07-01&end_date=2026-07-10')
            ->assertOk()
            ->assertJsonPath('data.comparison.income_change_percentage', null)
            ->assertJsonPath('data.comparison.expense_change_percentage', null)
            ->assertJsonPath('data.comparison.net_cashflow_change_percentage', null)
            ->assertJsonPath('data.comparison.status', 'unavailable');

        $json = $response->getContent();
        $this->assertStringNotContainsString('Infinity', $json);
        $this->assertStringNotContainsString('NaN', $json);
        $this->assertStringNotContainsString('+2.4%', $json);
        $this->assertStringNotContainsString('+8.1%', $json);
        $this->assertStringNotContainsString('-1.2%', $json);
        $this->assertStringNotContainsString('+12.5%', $json);
    }

    public function test_dashboard_includes_manual_csv_and_stellar_transactions_but_excludes_invalid_records(): void
    {
        [$user, $wallet, $expenseCategory, $incomeCategory] = $this->fixture();
        Sanctum::actingAs($user);

        $this->transaction($user, $wallet, $expenseCategory, [
            'amount' => 50000,
            'source' => 'manual',
            'happened_at' => '2026-07-01 08:00:00',
        ]);
        $this->transaction($user, $wallet, $expenseCategory, [
            'amount' => 60000,
            'source' => 'bank_csv',
            'happened_at' => '2026-07-02 08:00:00',
        ]);
        $this->transaction($user, $wallet, $expenseCategory, [
            'amount' => 70000,
            'source' => 'ewallet_csv',
            'happened_at' => '2026-07-03 08:00:00',
        ]);
        $this->transaction($user, $wallet, $incomeCategory, [
            'type' => 'income',
            'amount' => 80000,
            'source' => 'stellar',
            'external_transaction_id' => str_repeat('a', 64),
            'happened_at' => '2026-07-04 08:00:00',
        ]);
        $this->transaction($user, $wallet, $expenseCategory, [
            'type' => 'transfer',
            'amount' => 999999,
            'source' => 'manual',
            'happened_at' => '2026-07-05 08:00:00',
        ]);
        $deleted = $this->transaction($user, $wallet, $expenseCategory, [
            'amount' => 999999,
            'source' => 'bank_csv',
            'happened_at' => '2026-07-06 08:00:00',
        ]);
        $deleted->delete();

        $response = $this->getJson('/api/dashboard/summary?start_date=2026-07-01&end_date=2026-07-31')
            ->assertOk()
            ->assertJsonPath('data.summary.total_income', 80000)
            ->assertJsonPath('data.summary.total_expense', 180000)
            ->assertJsonPath('data.summary.transactions_count', 4);

        $sources = collect($response->json('data.source_distribution'))->keyBy('source');
        $this->assertSame(['manual', 'bank_csv', 'ewallet_csv', 'stellar'], $sources->keys()->all());
        $this->assertEquals(50000, $sources['manual']['amount']);
        $this->assertEquals(60000, $sources['bank_csv']['amount']);
        $this->assertEquals(70000, $sources['ewallet_csv']['amount']);
        $this->assertEquals(80000, $sources['stellar']['amount']);
    }

    public function test_dashboard_source_and_wallet_filters_respect_user_ownership(): void
    {
        [$user, $cashWallet, $expenseCategory, $incomeCategory] = $this->fixture();
        $bankWallet = $this->wallet($user, 'Bank BCA', 'bank', 900000);
        $otherUser = User::factory()->create();
        $otherWallet = $this->wallet($otherUser, 'Other Wallet');
        $otherCategory = $this->category($otherUser, 'Other Food');
        Sanctum::actingAs($user);

        $this->transaction($user, $cashWallet, $expenseCategory, [
            'amount' => 100000,
            'source' => 'manual',
            'happened_at' => '2026-07-01 08:00:00',
        ]);
        $this->transaction($user, $bankWallet, $incomeCategory, [
            'type' => 'income',
            'amount' => 500000,
            'source' => 'bank_csv',
            'happened_at' => '2026-07-02 08:00:00',
        ]);
        $this->transaction($otherUser, $otherWallet, $otherCategory, [
            'amount' => 999999,
            'source' => 'bank_csv',
            'happened_at' => '2026-07-02 08:00:00',
        ]);

        $this->getJson("/api/dashboard/summary?start_date=2026-07-01&end_date=2026-07-31&wallet_id={$bankWallet->id}")
            ->assertOk()
            ->assertJsonPath('data.summary.total_balance', 900000)
            ->assertJsonPath('data.summary.total_income', 500000)
            ->assertJsonPath('data.summary.total_expense', 0)
            ->assertJsonPath('data.summary.transactions_count', 1);

        $this->getJson('/api/dashboard/summary?start_date=2026-07-01&end_date=2026-07-31&source=bank_csv')
            ->assertOk()
            ->assertJsonPath('data.summary.total_income', 500000)
            ->assertJsonPath('data.summary.total_expense', 0)
            ->assertJsonPath('data.summary.transactions_count', 1);

        $this->getJson("/api/dashboard/summary?wallet_id={$otherWallet->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['wallet_id']);
    }

    public function test_dashboard_empty_period_returns_complete_empty_chart_response(): void
    {
        [$user] = $this->fixture();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/dashboard/summary?start_date=2026-07-01&end_date=2026-07-03')
            ->assertOk()
            ->assertJsonPath('data.summary.total_income', 0)
            ->assertJsonPath('data.summary.total_expense', 0)
            ->assertJsonPath('data.summary.net_cashflow', 0)
            ->assertJsonPath('data.summary.transactions_count', 0)
            ->assertJsonPath('data.expense_distribution', [])
            ->assertJsonPath('data.recent_transactions', []);

        $data = $response->json('data');
        $this->assertCount(3, $data['daily_cashflow']);
        $this->assertEquals([
            ['date' => '2026-07-01', 'income' => 0.0, 'expense' => 0.0],
            ['date' => '2026-07-02', 'income' => 0.0, 'expense' => 0.0],
            ['date' => '2026-07-03', 'income' => 0.0, 'expense' => 0.0],
        ], $data['daily_cashflow']);
    }

    private function fixture(): array
    {
        $user = User::factory()->create();
        $wallet = $this->wallet($user, 'Cash Wallet', 'cash', 1500000);
        $expenseCategory = $this->category($user, 'Belanja', 'expense');
        $incomeCategory = $this->category($user, 'Pemasukan', 'income');

        return [$user, $wallet, $expenseCategory, $incomeCategory];
    }

    private function wallet(User $user, string $name, string $type = 'cash', int $currentBalance = 0): Wallet
    {
        return Wallet::create([
            'user_id' => $user->id,
            'name' => $name,
            'type' => $type,
            'currency' => 'IDR',
            'opening_balance' => $currentBalance,
            'current_balance' => $currentBalance,
            'is_active' => true,
        ]);
    }

    private function category(User $user, string $name, string $type = 'expense'): Category
    {
        return Category::create([
            'user_id' => $user->id,
            'name' => $name,
            'type' => $type,
            'icon' => $type === 'income' ? 'wallet' : 'shopping-bag',
            'color' => $type === 'income' ? '#059669' : '#2563EB',
            'is_default' => false,
        ]);
    }

    private function transaction(User $user, Wallet $wallet, Category $category, array $overrides = []): Transaction
    {
        return Transaction::create(array_merge([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 100000,
            'fee' => 0,
            'currency' => 'IDR',
            'merchant' => 'Warung Sederhana',
            'description' => 'QRIS WARUNG SEDERHANA',
            'note' => 'QRIS WARUNG SEDERHANA',
            'source' => 'manual',
            'happened_at' => '2026-07-01 12:00:00',
            'categorization_status' => 'categorized',
            'category_source' => 'manual',
        ], $overrides));
    }
}
