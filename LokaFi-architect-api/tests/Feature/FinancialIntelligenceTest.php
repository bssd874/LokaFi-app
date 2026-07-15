<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Ai\AiProviderClientInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class FinancialIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_calculates_income_expense_cashflow_comparison_and_sources(): void
    {
        [$user, $wallet, $food] = $this->fixture();
        $incomeCategory = $this->category($user, 'Pemasukan', 'income');
        Sanctum::actingAs($user);

        $this->transaction($user, $wallet, $incomeCategory, [
            'type' => 'income',
            'amount' => 1000000,
            'source' => 'manual',
            'happened_at' => '2026-07-01 09:00:00',
        ]);
        $this->transaction($user, $wallet, $food, [
            'amount' => 200000,
            'fee' => 5000,
            'source' => 'bank_csv',
            'happened_at' => '2026-07-10 10:00:00',
        ]);
        $this->transaction($user, $wallet, $food, [
            'amount' => 100000,
            'source' => 'ewallet_csv',
            'happened_at' => '2026-07-31 23:59:59',
        ]);
        $this->transaction($user, $wallet, $incomeCategory, [
            'type' => 'income',
            'amount' => 500000,
            'source' => 'stellar',
            'happened_at' => '2026-07-15 10:00:00',
        ]);

        $this->transaction($user, $wallet, $incomeCategory, [
            'type' => 'income',
            'amount' => 800000,
            'happened_at' => '2026-05-31 00:00:00',
        ]);
        $this->transaction($user, $wallet, $food, [
            'amount' => 300000,
            'happened_at' => '2026-06-30 23:59:59',
        ]);
        $this->transaction($user, $wallet, $food, [
            'amount' => 999999,
            'happened_at' => '2026-05-30 23:59:59',
        ]);
        $this->transaction($user, $wallet, $food, [
            'amount' => 999999,
            'happened_at' => '2026-08-01 00:00:00',
        ]);

        $response = $this->getJson('/api/financial-intelligence/summary?start_date=2026-07-01&end_date=2026-07-31')
            ->assertOk()
            ->assertJsonPath('data.calculation_version', 'financial_analytics_v1')
            ->assertJsonPath('data.period.days', 31)
            ->assertJsonPath('data.comparison_period.start_date', '2026-05-31')
            ->assertJsonPath('data.comparison_period.end_date', '2026-06-30')
            ->assertJsonPath('data.summary.total_income', 1500000)
            ->assertJsonPath('data.summary.total_expense', 305000)
            ->assertJsonPath('data.summary.net_cashflow', 1195000)
            ->assertJsonPath('data.summary.savings_amount', 1195000)
            ->assertJsonPath('data.summary.savings_rate', 79.67)
            ->assertJsonPath('data.summary.average_daily_expense', 9838.71)
            ->assertJsonPath('data.summary.transaction_count', 4)
            ->assertJsonPath('data.comparison.previous_income', 800000)
            ->assertJsonPath('data.comparison.previous_expense', 300000)
            ->assertJsonPath('data.comparison.income.percentage_change', 87.5);

        $sources = collect($response->json('data.source_distribution'));
        $this->assertSame(205000, $sources->firstWhere('source', 'bank_csv')['amount']);
        $this->assertSame(500000, $sources->firstWhere('source', 'stellar')['amount']);
    }

    public function test_zero_income_and_zero_baseline_are_explicit(): void
    {
        [$user, $wallet, $category] = $this->fixture();
        Sanctum::actingAs($user);

        $this->transaction($user, $wallet, $category, [
            'amount' => 50000,
            'happened_at' => '2026-07-05 10:00:00',
        ]);

        $this->getJson('/api/financial-intelligence/summary?start_date=2026-07-01&end_date=2026-07-31')
            ->assertOk()
            ->assertJsonPath('data.summary.total_income', 0)
            ->assertJsonPath('data.summary.savings_rate', null)
            ->assertJsonPath('data.summary.savings_rate_status', 'zero_income')
            ->assertJsonPath('data.summary.income_to_expense_ratio', 0)
            ->assertJsonPath('data.comparison.expense.percentage_change', null)
            ->assertJsonPath('data.comparison.expense.status', 'zero_baseline');
    }

    public function test_category_trends_report_new_activity_without_infinite_percentage(): void
    {
        [$user, $wallet, $category] = $this->fixture();
        Sanctum::actingAs($user);

        $this->transaction($user, $wallet, $category, [
            'amount' => 120000,
            'happened_at' => '2026-07-12 10:00:00',
        ]);

        $this->getJson('/api/financial-intelligence/trends?start_date=2026-07-01&end_date=2026-07-31')
            ->assertOk()
            ->assertJsonPath('data.category_trends.0.category_id', $category->id)
            ->assertJsonPath('data.category_trends.0.current_amount', 120000)
            ->assertJsonPath('data.category_trends.0.previous_amount', 0)
            ->assertJsonPath('data.category_trends.0.percentage_change', null)
            ->assertJsonPath('data.category_trends.0.change_status', 'new_activity')
            ->assertJsonPath('data.category_trends.0.share_of_current_expense', 100)
            ->assertJsonPath('data.category_trends.0.transaction_frequency', 1);
    }

    public function test_budget_monitoring_thresholds_and_exhaustion_date(): void
    {
        [$user, $wallet] = $this->fixture();
        $normal = $this->category($user, 'Normal');
        $notice = $this->category($user, 'Notice');
        $warning = $this->category($user, 'Warning');
        $critical = $this->category($user, 'Critical');
        $exceeded = $this->category($user, 'Exceeded');
        Sanctum::actingAs($user);

        foreach ([$normal, $notice, $warning, $critical, $exceeded] as $category) {
            Budget::create([
                'user_id' => $user->id,
                'category_id' => $category->id,
                'month' => '2026-07',
                'amount' => 100000,
            ]);
        }

        $this->transaction($user, $wallet, $normal, ['amount' => 60000, 'happened_at' => '2026-07-31 10:00:00']);
        $this->transaction($user, $wallet, $notice, ['amount' => 75000, 'happened_at' => '2026-07-31 10:00:00']);
        $this->transaction($user, $wallet, $warning, ['amount' => 90000, 'happened_at' => '2026-07-31 10:00:00']);
        $this->transaction($user, $wallet, $critical, ['amount' => 97000, 'happened_at' => '2026-07-31 10:00:00']);
        $this->transaction($user, $wallet, $exceeded, ['amount' => 110000, 'happened_at' => '2026-07-20 10:00:00']);

        $response = $this->getJson('/api/financial-intelligence/budget-alerts?start_date=2026-07-01&end_date=2026-07-31')
            ->assertOk();

        $items = collect($response->json('data.items'))->keyBy('category_id');
        $this->assertSame('normal', $items[$normal->id]['severity']);
        $this->assertSame('notice', $items[$notice->id]['severity']);
        $this->assertSame('warning', $items[$warning->id]['severity']);
        $this->assertSame('critical', $items[$critical->id]['severity']);
        $this->assertSame('exceeded', $items[$exceeded->id]['severity']);
        $this->assertSame('2026-07-20', $items[$exceeded->id]['estimated_budget_exhaustion_date']);
    }

    public function test_budget_projection_can_mark_critical_before_budget_is_exceeded(): void
    {
        [$user, $wallet, $category] = $this->fixture();
        Sanctum::actingAs($user);

        Budget::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'month' => '2026-07',
            'amount' => 100000,
        ]);

        $this->transaction($user, $wallet, $category, ['amount' => 40000, 'happened_at' => '2026-07-01 10:00:00']);
        $this->transaction($user, $wallet, $category, ['amount' => 45000, 'happened_at' => '2026-07-05 10:00:00']);

        $this->getJson('/api/financial-intelligence/budget-alerts?start_date=2026-07-01&end_date=2026-07-10')
            ->assertOk()
            ->assertJsonPath('data.items.0.amount_spent', 85000)
            ->assertJsonPath('data.items.0.usage_percentage', 85)
            ->assertJsonPath('data.items.0.severity', 'critical')
            ->assertJsonPath('data.items.0.estimated_budget_exhaustion_date', '2026-07-12');
    }

    public function test_anomalies_detect_unusual_amount_category_spike_budget_duplicate_and_negative_cashflow(): void
    {
        [$user, $wallet, $category] = $this->fixture();
        $incomeCategory = $this->category($user, 'Income', 'income');
        Sanctum::actingAs($user);

        Budget::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'month' => '2026-07',
            'amount' => 300000,
        ]);

        foreach ([90000, 95000, 100000, 105000] as $index => $amount) {
            $this->transaction($user, $wallet, $category, [
                'amount' => $amount,
                'happened_at' => '2026-05-' . str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) . ' 10:00:00',
            ]);
        }
        $this->transaction($user, $wallet, $category, [
            'amount' => 110000,
            'happened_at' => '2026-06-10 10:00:00',
        ]);

        $this->transaction($user, $wallet, $incomeCategory, [
            'type' => 'income',
            'amount' => 100000,
            'happened_at' => '2026-07-01 08:00:00',
        ]);
        $this->transaction($user, $wallet, $category, [
            'amount' => 400000,
            'normalized_merchant' => 'alpha store',
            'happened_at' => '2026-07-10 10:00:00',
        ]);
        $this->transaction($user, $wallet, $category, [
            'amount' => 30000,
            'normalized_merchant' => 'duplicate cafe',
            'happened_at' => '2026-07-12 10:00:00',
        ]);
        $this->transaction($user, $wallet, $category, [
            'amount' => 30000,
            'normalized_merchant' => 'duplicate cafe',
            'happened_at' => '2026-07-12 10:10:00',
        ]);

        $response = $this->getJson('/api/financial-intelligence/anomalies?start_date=2026-07-01&end_date=2026-07-31')
            ->assertOk();

        $types = collect($response->json('data.items'))->pluck('type')->all();

        $this->assertContains('unusual_amount', $types);
        $this->assertContains('category_spending_increase', $types);
        $this->assertContains('budget_overspending', $types);
        $this->assertContains('duplicate_like_transaction', $types);
        $this->assertContains('negative_cashflow_risk', $types);
    }

    public function test_anomaly_detection_reports_insufficient_history_without_false_amount_alert(): void
    {
        [$user, $wallet, $category] = $this->fixture();
        Sanctum::actingAs($user);

        $this->transaction($user, $wallet, $category, [
            'amount' => 100000,
            'happened_at' => '2026-06-01 10:00:00',
        ]);
        $this->transaction($user, $wallet, $category, [
            'amount' => 900000,
            'happened_at' => '2026-07-01 10:00:00',
        ]);

        $response = $this->getJson('/api/financial-intelligence/anomalies?start_date=2026-07-01&end_date=2026-07-31')
            ->assertOk();

        $types = collect($response->json('data.items'))->pluck('type')->all();
        $this->assertNotContains('unusual_amount', $types);
        $this->assertSame('insufficient_history', $response->json('data.insufficient_history.0.status'));
    }

    public function test_wallet_source_category_filters_and_user_isolation(): void
    {
        [$user, $wallet, $category] = $this->fixture();
        $otherWallet = $this->wallet($user, 'Other Wallet');
        [$otherUser, $otherUserWallet, $otherCategory] = $this->fixture();
        Sanctum::actingAs($user);

        $this->transaction($user, $wallet, $category, [
            'amount' => 100000,
            'source' => 'bank_csv',
            'happened_at' => '2026-07-10 10:00:00',
        ]);
        $this->transaction($user, $otherWallet, $category, [
            'amount' => 500000,
            'source' => 'bank_csv',
            'happened_at' => '2026-07-10 10:00:00',
        ]);
        $this->transaction($user, $wallet, $category, [
            'amount' => 400000,
            'source' => 'stellar',
            'happened_at' => '2026-07-10 10:00:00',
        ]);
        $this->transaction($otherUser, $otherUserWallet, $otherCategory, [
            'amount' => 999999,
            'source' => 'bank_csv',
            'happened_at' => '2026-07-10 10:00:00',
        ]);

        $this->getJson("/api/financial-intelligence/summary?start_date=2026-07-01&end_date=2026-07-31&wallet_id={$wallet->id}&source=bank_csv&category_id={$category->id}")
            ->assertOk()
            ->assertJsonPath('data.summary.total_expense', 100000)
            ->assertJsonPath('data.summary.transaction_count', 1)
            ->assertJsonPath('data.filters.wallet_id', $wallet->id)
            ->assertJsonPath('data.filters.source', 'bank_csv');

        $this->getJson("/api/financial-intelligence/summary?start_date=2026-07-01&end_date=2026-07-31&wallet_id={$otherUserWallet->id}")
            ->assertUnprocessable();
    }

    public function test_external_ai_provider_is_not_called_by_phase_d_endpoints(): void
    {
        [$user, $wallet, $category] = $this->fixture();
        Sanctum::actingAs($user);

        $this->transaction($user, $wallet, $category, [
            'amount' => 100000,
            'happened_at' => '2026-07-10 10:00:00',
        ]);

        $this->mock(AiProviderClientInterface::class, function ($mock) {
            $mock->shouldReceive('categorize')->never();
        });

        $this->getJson('/api/financial-intelligence/summary?start_date=2026-07-01&end_date=2026-07-31')
            ->assertOk();
        $this->getJson('/api/financial-intelligence/anomalies?start_date=2026-07-01&end_date=2026-07-31')
            ->assertOk();
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    private function fixture(): array
    {
        $user = User::factory()->create();
        $wallet = $this->wallet($user);
        $category = $this->category($user, 'Makanan');

        return [$user, $wallet, $category];
    }

    private function wallet(User $user, string $name = 'Cash Wallet'): Wallet
    {
        return Wallet::create([
            'user_id' => $user->id,
            'name' => $name,
            'type' => 'cash',
            'currency' => 'IDR',
            'opening_balance' => 0,
            'current_balance' => 0,
            'is_active' => true,
        ]);
    }

    private function category(User $user, string $name, string $type = 'expense'): Category
    {
        return Category::create([
            'user_id' => $user->id,
            'name' => $name,
            'type' => $type,
            'icon' => 'tag',
            'color' => '#2563EB',
            'is_default' => false,
        ]);
    }

    private function transaction(User $user, Wallet $wallet, Category $category, array $overrides = []): Transaction
    {
        $type = $overrides['type'] ?? $category->type;

        return Transaction::create(array_merge([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'category_id' => $category->id,
            'type' => $type,
            'amount' => 50000,
            'fee' => 0,
            'currency' => 'IDR',
            'merchant' => $category->name,
            'normalized_merchant' => strtolower($category->name),
            'description' => "{$category->name} test transaction",
            'note' => "{$category->name} test transaction",
            'reference_code' => uniqid('fi-', true),
            'happened_at' => '2026-07-01 10:00:00',
            'source' => 'manual',
            'sanitized_description' => "{$category->name} test transaction",
            'normalized_description' => strtolower($category->name) . ' test transaction',
            'categorization_status' => 'categorized',
            'category_source' => 'user',
            'categorization_confidence' => 'high',
            'categorization_confidence_score' => 100,
            'categorized_at' => '2026-07-01 10:00:00',
        ], $overrides));
    }
}
