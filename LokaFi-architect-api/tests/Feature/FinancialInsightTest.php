<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\Category;
use App\Models\FinancialInsight;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Ai\AiProviderClientInterface;
use App\Services\Ai\AiProviderException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class FinancialInsightTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.ai.enabled' => true,
            'services.ai.provider' => 'fake',
            'services.ai.model' => 'fake-category-model',
            'services.ai.financial_insights.enabled' => true,
            'services.ai.financial_insights.model' => 'fake-insight-model',
            'services.ai.financial_insights.prompt_version' => 'financial_insight_v1',
            'services.ai.financial_insights.cache_hours' => 24,
        ]);
    }

    public function test_valid_structured_insight_generation_uses_deterministic_phase_d_payload(): void
    {
        [$user, $wallet, $category] = $this->fixture();
        Sanctum::actingAs($user);
        $this->transaction($user, $wallet, $category, [
            'amount' => 150000,
            'raw_payload' => ['token' => 'secret-token', 'otp' => '123456'],
        ]);

        $this->mock(AiProviderClientInterface::class, function ($mock) {
            $mock->shouldReceive('categorize')
                ->once()
                ->with(Mockery::on(function (array $payload) {
                    $encoded = json_encode($payload);

                    return ($payload['task'] ?? null) === 'financial_insight'
                        && ($payload['calculation_version'] ?? null) === 'financial_analytics_v1'
                        && isset($payload['financial_summary']['expense'])
                        && in_array('summary.total_expense', $payload['allowed_evidence_keys'], true)
                        && !str_contains($encoded, 'secret-token')
                        && !str_contains($encoded, '123456')
                        && !array_key_exists('raw_payload', $payload);
                }))
                ->andReturn($this->validInsightJson(['summary.total_expense']));
        });

        $response = $this->postJson('/api/financial-intelligence/insight?start_date=2026-07-01&end_date=2026-07-31')
            ->assertOk()
            ->assertJsonPath('data.validation_status', FinancialInsight::STATUS_VALID)
            ->assertJsonPath('data.insight.headline', 'Ringkasan keuangan perlu ditinjau');

        $this->assertSame(150000, $response->json('data.supporting_metrics')['summary.total_expense']['value']);

        $this->assertDatabaseHas('financial_insights', [
            'user_id' => $user->id,
            'validation_status' => FinancialInsight::STATUS_VALID,
        ]);
    }

    public function test_fake_provider_generates_deterministic_valid_insight(): void
    {
        [$user, $wallet, $category] = $this->fixture();
        Sanctum::actingAs($user);
        $this->transaction($user, $wallet, $category);

        $this->postJson('/api/financial-intelligence/insight?start_date=2026-07-01&end_date=2026-07-31')
            ->assertOk()
            ->assertJsonPath('data.validation_status', FinancialInsight::STATUS_VALID)
            ->assertJsonPath('data.insight.disclaimer', 'AI-generated insights are informational and based on recorded transaction data. They are not professional financial advice.');
    }

    public function test_invalid_json_and_missing_fields_are_rejected_without_storing_output(): void
    {
        [$user, $wallet, $category] = $this->fixture();
        Sanctum::actingAs($user);
        $this->transaction($user, $wallet, $category);
        $this->mockRawProviderResponse('not-json');

        $this->postJson('/api/financial-intelligence/insight?start_date=2026-07-01&end_date=2026-07-31')
            ->assertOk()
            ->assertJsonPath('data.validation_status', FinancialInsight::STATUS_INVALID_RESPONSE)
            ->assertJsonPath('data.error_code', 'invalid_json')
            ->assertJsonPath('data.insight', null);

        $this->assertDatabaseHas('financial_insights', [
            'validation_status' => FinancialInsight::STATUS_INVALID_RESPONSE,
            'structured_insight' => null,
        ]);
    }

    public function test_invalid_provider_response_is_retried_once_before_storing_a_valid_insight(): void
    {
        [$user, $wallet, $category] = $this->fixture();
        Sanctum::actingAs($user);
        $this->transaction($user, $wallet, $category);

        $this->mock(AiProviderClientInterface::class, function ($mock) {
            $mock->shouldReceive('categorize')
                ->twice()
                ->andReturn(
                    'not-json',
                    $this->validInsightJson(['summary.total_expense']),
                );
        });

        $this->postJson('/api/financial-intelligence/insight?start_date=2026-07-01&end_date=2026-07-31')
            ->assertOk()
            ->assertJsonPath('data.validation_status', FinancialInsight::STATUS_VALID)
            ->assertJsonPath('data.error_code', null)
            ->assertJsonPath('data.insight.headline', 'Ringkasan keuangan perlu ditinjau');

        $this->assertDatabaseCount('financial_insights', 1);
        $this->assertDatabaseMissing('financial_insights', [
            'validation_status' => FinancialInsight::STATUS_INVALID_RESPONSE,
        ]);
    }

    public function test_invalid_enum_unsupported_evidence_and_hallucinated_number_are_rejected(): void
    {
        [$user, $wallet, $category] = $this->fixture();
        Sanctum::actingAs($user);
        $this->transaction($user, $wallet, $category);

        $this->mock(AiProviderClientInterface::class, function ($mock) {
            $mock->shouldReceive('categorize')
                ->times(6)
                ->andReturn(
                    $this->validInsightJson(['summary.total_expense'], [
                        'highlights' => [[
                            'type' => 'urgent',
                            'title' => 'Perlu dicek',
                            'description' => 'Perhatikan metrik pendukung.',
                            'evidence_keys' => ['summary.total_expense'],
                        ]],
                    ]),
                    $this->validInsightJson(['summary.total_expense'], [
                        'highlights' => [[
                            'type' => 'urgent',
                            'title' => 'Perlu dicek',
                            'description' => 'Perhatikan metrik pendukung.',
                            'evidence_keys' => ['summary.total_expense'],
                        ]],
                    ]),
                    $this->validInsightJson(['not.allowed']),
                    $this->validInsightJson(['not.allowed']),
                    $this->validInsightJson(['summary.total_expense'], [
                        'summary' => 'Pengeluaran naik 20 persen dan perlu ditinjau.',
                    ]),
                    $this->validInsightJson(['summary.total_expense'], [
                        'summary' => 'Pengeluaran naik 20 persen dan perlu ditinjau.',
                    ]),
                );
        });

        $this->postJson('/api/financial-intelligence/insight?start_date=2026-07-01&end_date=2026-07-31')
            ->assertOk()
            ->assertJsonPath('data.error_code', 'invalid_highlight_type');

        $this->postJson('/api/financial-intelligence/insight/regenerate?start_date=2026-07-01&end_date=2026-07-31')
            ->assertOk()
            ->assertJsonPath('data.error_code', 'unsupported_evidence_key');

        $this->postJson('/api/financial-intelligence/insight/regenerate?start_date=2026-07-01&end_date=2026-07-31')
            ->assertOk()
            ->assertJsonPath('data.error_code', 'hallucinated_number');
    }

    public function test_oversized_arrays_and_product_recommendations_are_rejected(): void
    {
        [$user, $wallet, $category] = $this->fixture();
        Sanctum::actingAs($user);
        $this->transaction($user, $wallet, $category);

        $tooManyHighlights = array_fill(0, 6, [
            'type' => 'neutral',
            'title' => 'Perlu ditinjau',
            'description' => 'Perhatikan metrik pendukung.',
            'evidence_keys' => ['summary.total_expense'],
        ]);

        $this->mock(AiProviderClientInterface::class, function ($mock) use ($tooManyHighlights) {
            $mock->shouldReceive('categorize')
                ->times(4)
                ->andReturn(
                    $this->validInsightJson(['summary.total_expense'], [
                        'highlights' => $tooManyHighlights,
                    ]),
                    $this->validInsightJson(['summary.total_expense'], [
                        'highlights' => $tooManyHighlights,
                    ]),
                    $this->validInsightJson(['summary.total_expense'], [
                        'recommended_actions' => [[
                            'priority' => 'high',
                            'title' => 'Buy crypto sekarang',
                            'description' => 'Ini bukan arahan yang aman.',
                            'related_metric' => 'summary.total_expense',
                        ]],
                    ]),
                    $this->validInsightJson(['summary.total_expense'], [
                        'recommended_actions' => [[
                            'priority' => 'high',
                            'title' => 'Buy crypto sekarang',
                            'description' => 'Ini bukan arahan yang aman.',
                            'related_metric' => 'summary.total_expense',
                        ]],
                    ]),
                );
        });

        $this->postJson('/api/financial-intelligence/insight?start_date=2026-07-01&end_date=2026-07-31')
            ->assertOk()
            ->assertJsonPath('data.error_code', 'invalid_highlights');

        $this->postJson('/api/financial-intelligence/insight/regenerate?start_date=2026-07-01&end_date=2026-07-31')
            ->assertOk()
            ->assertJsonPath('data.error_code', 'forbidden_financial_product_recommendation');
    }

    public function test_provider_timeout_and_disabled_provider_fallback_keep_analytics_available(): void
    {
        [$user, $wallet, $category] = $this->fixture();
        Sanctum::actingAs($user);
        $this->transaction($user, $wallet, $category);

        $this->mock(AiProviderClientInterface::class, function ($mock) {
            $mock->shouldReceive('categorize')
                ->once()
                ->andThrow(new AiProviderException('provider_timeout_or_network_error'));
        });

        $response = $this->postJson('/api/financial-intelligence/insight?start_date=2026-07-01&end_date=2026-07-31')
            ->assertOk()
            ->assertJsonPath('data.validation_status', FinancialInsight::STATUS_PROVIDER_ERROR)
            ->assertJsonPath('data.error_code', 'provider_timeout_or_network_error');

        $this->assertSame(50000, $response->json('data.supporting_metrics')['summary.total_expense']['value']);

        config(['services.ai.financial_insights.enabled' => false]);

        $this->postJson('/api/financial-intelligence/insight/regenerate?start_date=2026-07-01&end_date=2026-07-31')
            ->assertOk()
            ->assertJsonPath('data.validation_status', FinancialInsight::STATUS_DISABLED)
            ->assertJsonPath('data.insight', null);
    }

    public function test_cache_reuse_and_invalidation_when_analytics_change(): void
    {
        [$user, $wallet, $category] = $this->fixture();
        Sanctum::actingAs($user);
        $this->transaction($user, $wallet, $category);

        $this->mock(AiProviderClientInterface::class, function ($mock) {
            $mock->shouldReceive('categorize')
                ->twice()
                ->andReturn($this->validInsightJson(['summary.total_expense']));
        });

        $first = $this->postJson('/api/financial-intelligence/insight?start_date=2026-07-01&end_date=2026-07-31')
            ->assertOk()
            ->assertJsonPath('data.validation_status', FinancialInsight::STATUS_VALID)
            ->assertJsonPath('data.cached', false);

        $this->postJson('/api/financial-intelligence/insight?start_date=2026-07-01&end_date=2026-07-31')
            ->assertOk()
            ->assertJsonPath('data.cached', true)
            ->assertJsonPath('data.input_hash', $first->json('data.input_hash'));

        $this->transaction($user, $wallet, $category, [
            'amount' => 25000,
            'happened_at' => '2026-07-20 10:00:00',
        ]);

        $second = $this->postJson('/api/financial-intelligence/insight?start_date=2026-07-01&end_date=2026-07-31')
            ->assertOk()
            ->assertJsonPath('data.cached', false);

        $this->assertNotSame($first->json('data.input_hash'), $second->json('data.input_hash'));
    }

    public function test_regenerate_action_bypasses_cache_and_history_is_user_scoped(): void
    {
        [$user, $wallet, $category] = $this->fixture();
        [$otherUser, $otherWallet, $otherCategory] = $this->fixture();
        Sanctum::actingAs($user);
        $this->transaction($user, $wallet, $category);
        $this->transaction($otherUser, $otherWallet, $otherCategory);

        $this->mock(AiProviderClientInterface::class, function ($mock) {
            $mock->shouldReceive('categorize')
                ->twice()
                ->andReturn($this->validInsightJson(['summary.total_expense']));
        });

        $this->postJson('/api/financial-intelligence/insight?start_date=2026-07-01&end_date=2026-07-31')->assertOk();
        $this->postJson('/api/financial-intelligence/insight/regenerate?start_date=2026-07-01&end_date=2026-07-31')
            ->assertOk()
            ->assertJsonPath('data.cached', false);

        FinancialInsight::create([
            'user_id' => $otherUser->id,
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'analytics_version' => 'financial_analytics_v1',
            'input_hash' => str_repeat('a', 64),
            'provider' => 'fake',
            'model' => 'fake',
            'prompt_version' => 'financial_insight_v1',
            'structured_insight' => json_decode($this->validInsightJson(['summary.total_expense']), true),
            'validation_status' => FinancialInsight::STATUS_VALID,
            'generated_at' => now(),
        ]);

        $this->getJson('/api/financial-intelligence/insight/history?per_page=10')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 2);
    }

    public function test_generation_rate_limiting_is_enforced(): void
    {
        [$user, $wallet, $category] = $this->fixture();
        Sanctum::actingAs($user);
        $this->transaction($user, $wallet, $category);

        config(['services.ai.financial_insights.enabled' => false]);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/financial-intelligence/insight?start_date=2026-07-01&end_date=2026-07-31')
                ->assertOk();
        }

        $this->postJson('/api/financial-intelligence/insight?start_date=2026-07-01&end_date=2026-07-31')
            ->assertStatus(429);
    }

    public function test_phase_c_ai_categorization_still_uses_category_schema(): void
    {
        [$user, $wallet, $category] = $this->fixture();
        Sanctum::actingAs($user);
        $transaction = $this->transaction($user, $wallet, $category, [
            'category_id' => null,
            'description' => 'ALPHA UNCLEAR',
            'normalized_merchant' => null,
            'normalized_description' => null,
            'categorization_status' => 'review_required',
            'category_source' => 'review_required',
        ]);

        $this->mock(AiProviderClientInterface::class, function ($mock) use ($category) {
            $mock->shouldReceive('categorize')
                ->once()
                ->with(Mockery::on(fn (array $payload) => ($payload['task'] ?? null) !== 'financial_insight'))
                ->andReturn(json_encode([
                    'category_id' => $category->id,
                    'confidence' => 0.7,
                    'needs_review' => true,
                    'reason' => 'Valid category suggestion.',
                ], JSON_THROW_ON_ERROR));
        });

        $this->postJson("/api/transactions/{$transaction->id}/ai-category-suggestion")
            ->assertOk()
            ->assertJsonPath('data.source', 'ai_suggestion');
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    private function fixture(): array
    {
        $user = User::factory()->create();
        $wallet = Wallet::create([
            'user_id' => $user->id,
            'name' => 'Insight Wallet',
            'type' => 'cash',
            'currency' => 'IDR',
            'opening_balance' => 0,
            'current_balance' => 0,
            'is_active' => true,
        ]);
        $category = Category::create([
            'user_id' => $user->id,
            'name' => 'Makanan',
            'type' => 'expense',
            'icon' => 'tag',
            'color' => '#2563EB',
            'is_default' => false,
        ]);

        Budget::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'month' => '2026-07',
            'amount' => 100000,
        ]);

        return [$user, $wallet, $category];
    }

    private function transaction(User $user, Wallet $wallet, Category $category, array $overrides = []): Transaction
    {
        return Transaction::create(array_merge([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 50000,
            'fee' => 0,
            'currency' => 'IDR',
            'merchant' => 'Insight Merchant',
            'normalized_merchant' => 'insight merchant',
            'description' => 'Insight transaction',
            'note' => 'Insight transaction',
            'reference_code' => uniqid('insight-', true),
            'happened_at' => '2026-07-10 10:00:00',
            'source' => 'manual',
            'raw_payload' => null,
            'sanitized_description' => 'Insight transaction',
            'normalized_description' => 'insight transaction',
            'categorization_status' => 'categorized',
            'category_source' => 'user',
            'categorization_confidence' => 'high',
            'categorization_confidence_score' => 100,
            'categorized_at' => '2026-07-10 10:00:00',
        ], $overrides));
    }

    private function mockRawProviderResponse(string $response): void
    {
        $this->mock(AiProviderClientInterface::class, function ($mock) use ($response) {
            $mock->shouldReceive('categorize')
                ->twice()
                ->andReturn($response);
        });
    }

    private function validInsightJson(array $evidenceKeys, array $overrides = []): string
    {
        $payload = array_merge([
            'headline' => 'Ringkasan keuangan perlu ditinjau',
            'summary' => 'Analisis ini memakai metrik terpercaya dari sistem dan tidak menghitung ulang angka.',
            'highlights' => [[
                'type' => 'neutral',
                'title' => 'Perhatikan metrik utama',
                'description' => 'Gunakan metrik pendukung untuk memahami kondisi periode ini.',
                'evidence_keys' => $evidenceKeys,
            ]],
            'recommended_actions' => [[
                'priority' => 'medium',
                'title' => 'Tinjau pengeluaran rutin',
                'description' => 'Mulai dari kategori yang terlihat paling penting pada metrik pendukung.',
                'related_metric' => $evidenceKeys[0] ?? null,
            ]],
            'disclaimer' => 'AI-generated insights are informational and based on recorded transaction data. They are not professional financial advice.',
        ], $overrides);

        return json_encode($payload, JSON_THROW_ON_ERROR);
    }
}
