<?php

namespace Tests\Feature;

use App\Models\AiCategorySuggestion;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\TransactionCategoryMapping;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Ai\AiProviderClientInterface;
use App\Services\Ai\AiProviderException;
use App\Services\DefaultCategoryService;
use App\Services\TransactionCategorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class AiCategorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.ai.enabled' => true,
            'services.ai.provider' => 'fake',
            'services.ai.model' => 'fake-category-model',
        ]);
    }

    public function test_deterministic_categorization_prevents_unnecessary_ai_calls(): void
    {
        [$user, $wallet] = $this->fixture();
        app(DefaultCategoryService::class)->ensureForUser($user);
        $transaction = $this->transaction($user, $wallet, [
            'description' => 'QRIS KOPI PAGI',
            'merchant' => 'Kopi Pagi',
        ]);
        Sanctum::actingAs($user);

        $this->mock(AiProviderClientInterface::class, function ($mock) {
            $mock->shouldReceive('categorize')->never();
        });

        $this->postJson("/api/transactions/{$transaction->id}/ai-category-suggestion")
            ->assertOk()
            ->assertJsonPath('data.skipped_ai', true)
            ->assertJsonPath('data.source', TransactionCategorizationService::SOURCE_DEFAULT_RULE);
    }

    public function test_unresolved_transaction_calls_ai_provider(): void
    {
        [$user, $wallet, $category] = $this->fixtureWithCategory('Belanja Khusus');
        $transaction = $this->transaction($user, $wallet, ['description' => 'ALPHA OMEGA TEST']);
        Sanctum::actingAs($user);

        $this->mockProviderResponse($category->id, 0.72, true, 'Ambiguous but closest allowed category.');

        $this->postJson("/api/transactions/{$transaction->id}/ai-category-suggestion")
            ->assertOk()
            ->assertJsonPath('data.source', 'ai_suggestion')
            ->assertJsonPath('data.suggestion.category.id', $category->id)
            ->assertJsonPath('data.suggestion.confidence_label', 'medium');

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'suggested_category_id' => $category->id,
            'category_source' => 'ai_suggestion',
            'categorization_status' => 'review_required',
        ]);

        $this->assertDatabaseHas('ai_category_suggestions', [
            'transaction_id' => $transaction->id,
            'category_id' => $category->id,
            'validation_status' => AiCategorySuggestion::STATUS_VALID,
        ]);
    }

    public function test_accepted_user_mapping_has_priority_over_ai(): void
    {
        [$user, $wallet, $category] = $this->fixtureWithCategory('Mapping Category');
        $first = $this->transaction($user, $wallet, ['description' => 'PEMBAYARAN STUDIO DELTA']);
        $second = $this->transaction($user, $wallet, ['description' => 'TRX STUDIO DELTA']);
        Sanctum::actingAs($user);

        $this->patchJson("/api/transactions/{$first->id}/category/correct", [
            'category_id' => $category->id,
        ])->assertOk();

        $this->mock(AiProviderClientInterface::class, function ($mock) {
            $mock->shouldReceive('categorize')->never();
        });

        $this->postJson("/api/transactions/{$second->id}/ai-category-suggestion")
            ->assertOk()
            ->assertJsonPath('data.skipped_ai', true)
            ->assertJsonPath('data.source', TransactionCategorizationService::SOURCE_VERIFIED_MAPPING);
    }

    public function test_ai_cannot_choose_another_users_category(): void
    {
        [$user, $wallet] = $this->fixture();
        [, , $otherCategory] = $this->fixtureWithCategory('Other Category');
        $this->category($user, 'User Category');
        $transaction = $this->transaction($user, $wallet, ['description' => 'ALPHA ONLY']);
        Sanctum::actingAs($user);

        $this->mockProviderResponse($otherCategory->id, 0.8, true, 'Wrong owner category.');

        $this->postJson("/api/transactions/{$transaction->id}/ai-category-suggestion")
            ->assertOk()
            ->assertJsonPath('data.validation_status', AiCategorySuggestion::STATUS_INVALID_RESPONSE)
            ->assertJsonPath('data.error_code', 'invalid_category');

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'suggested_category_id' => null,
            'categorization_status' => 'review_required',
        ]);
    }

    public function test_transaction_type_mismatch_category_is_rejected(): void
    {
        [$user, $wallet] = $this->fixture();
        $this->category($user, 'Expense Category', 'expense');
        $income = $this->category($user, 'Income Category', 'income');
        $transaction = $this->transaction($user, $wallet, [
            'type' => 'expense',
            'description' => 'ALPHA TYPE MISMATCH',
        ]);
        Sanctum::actingAs($user);

        $this->mockProviderResponse($income->id, 0.85, true, 'Wrong type category.');

        $this->postJson("/api/transactions/{$transaction->id}/ai-category-suggestion")
            ->assertOk()
            ->assertJsonPath('data.validation_status', AiCategorySuggestion::STATUS_INVALID_RESPONSE);
    }

    public function test_invalid_json_response_is_rejected(): void
    {
        [$user, $wallet] = $this->fixture();
        $this->category($user, 'Belanja');
        $transaction = $this->transaction($user, $wallet);
        Sanctum::actingAs($user);
        $this->mockRawProviderResponse('not-json');

        $this->postJson("/api/transactions/{$transaction->id}/ai-category-suggestion")
            ->assertOk()
            ->assertJsonPath('data.error_code', 'invalid_json');
    }

    public function test_missing_fields_response_is_rejected(): void
    {
        [$user, $wallet] = $this->fixture();
        $this->category($user, 'Belanja');
        $transaction = $this->transaction($user, $wallet);
        Sanctum::actingAs($user);
        $this->mockRawProviderResponse(json_encode(['category_id' => null]));

        $this->postJson("/api/transactions/{$transaction->id}/ai-category-suggestion")
            ->assertOk()
            ->assertJsonPath('data.error_code', 'missing_fields');
    }

    public function test_confidence_outside_range_is_rejected(): void
    {
        [$user, $wallet, $category] = $this->fixtureWithCategory('Belanja');
        $transaction = $this->transaction($user, $wallet);
        Sanctum::actingAs($user);
        $this->mockProviderResponse($category->id, 1.2, true, 'Too confident.');

        $this->postJson("/api/transactions/{$transaction->id}/ai-category-suggestion")
            ->assertOk()
            ->assertJsonPath('data.error_code', 'invalid_confidence');
    }

    public function test_timeout_is_handled_safely(): void
    {
        [$user, $wallet] = $this->fixture();
        $this->category($user, 'Belanja');
        $transaction = $this->transaction($user, $wallet);
        Sanctum::actingAs($user);
        $this->mockProviderException('provider_timeout_or_network_error');

        $this->postJson("/api/transactions/{$transaction->id}/ai-category-suggestion")
            ->assertOk()
            ->assertJsonPath('data.validation_status', AiCategorySuggestion::STATUS_PROVIDER_ERROR)
            ->assertJsonPath('data.error_code', 'provider_timeout_or_network_error');
    }

    public function test_provider_failure_is_handled_safely(): void
    {
        [$user, $wallet] = $this->fixture();
        $this->category($user, 'Belanja');
        $transaction = $this->transaction($user, $wallet);
        Sanctum::actingAs($user);
        $this->mockProviderException('provider_http_error');

        $this->postJson("/api/transactions/{$transaction->id}/ai-category-suggestion")
            ->assertOk()
            ->assertJsonPath('data.validation_status', AiCategorySuggestion::STATUS_PROVIDER_ERROR)
            ->assertJsonPath('data.user_message', 'AI provider belum tersedia. Review manual diperlukan.');
    }

    public function test_invalid_category_id_is_rejected(): void
    {
        [$user, $wallet] = $this->fixture();
        $this->category($user, 'Belanja');
        $transaction = $this->transaction($user, $wallet);
        Sanctum::actingAs($user);
        $this->mockProviderResponse(999999, 0.7, true, 'Unknown category.');

        $this->postJson("/api/transactions/{$transaction->id}/ai-category-suggestion")
            ->assertOk()
            ->assertJsonPath('data.error_code', 'invalid_category');
    }

    public function test_user_ownership_is_enforced(): void
    {
        [$user] = $this->fixture();
        [$otherUser, $otherWallet] = $this->fixture();
        $transaction = $this->transaction($otherUser, $otherWallet);
        Sanctum::actingAs($user);

        $this->postJson("/api/transactions/{$transaction->id}/ai-category-suggestion")
            ->assertForbidden();
    }

    public function test_ai_payload_contains_sanitized_data_only(): void
    {
        [$user, $wallet, $category] = $this->fixtureWithCategory('Belanja');
        $transaction = $this->transaction($user, $wallet, [
            'description' => 'TOKO TEST otp 123456 token abc123 rek 1234567890',
            'raw_payload' => ['token' => 'secret-token'],
        ]);
        Sanctum::actingAs($user);

        $this->mock(AiProviderClientInterface::class, function ($mock) use ($category) {
            $mock->shouldReceive('categorize')
                ->once()
                ->with(Mockery::on(function (array $payload) {
                    $encoded = json_encode($payload);

                    return !str_contains($encoded, '123456')
                        && !str_contains($encoded, 'abc123')
                        && !str_contains($encoded, 'secret-token')
                        && !array_key_exists('raw_payload', $payload);
                }))
                ->andReturn($this->responseJson($category->id, 0.7, true, 'Sanitized payload accepted.'));
        });

        $this->postJson("/api/transactions/{$transaction->id}/ai-category-suggestion")
            ->assertOk()
            ->assertJsonPath('data.source', 'ai_suggestion');
    }

    public function test_ai_suggestion_acceptance_creates_verified_feedback_mapping(): void
    {
        [$user, $wallet, $category] = $this->fixtureWithCategory('Belanja');
        $transaction = $this->transaction($user, $wallet, ['description' => 'ALPHA ACCEPT AI']);
        Sanctum::actingAs($user);
        $this->mockProviderResponse($category->id, 0.77, true, 'Acceptable suggestion.');

        $this->postJson("/api/transactions/{$transaction->id}/ai-category-suggestion")->assertOk();

        $this->postJson("/api/transactions/{$transaction->id}/accept-ai-category")
            ->assertOk()
            ->assertJsonPath('data.category_id', $category->id)
            ->assertJsonPath('data.category_source', 'user');

        $this->assertDatabaseHas('transaction_category_labels', [
            'transaction_id' => $transaction->id,
            'category_id' => $category->id,
            'labeled_by' => 'accepted_ai_suggestion',
            'is_verified' => true,
        ]);

        $this->assertSame(1, TransactionCategoryMapping::count());
    }

    public function test_manual_correction_overrides_ai_suggestion(): void
    {
        [$user, $wallet] = $this->fixture();
        $aiCategory = $this->category($user, 'AI Category');
        $manualCategory = $this->category($user, 'Manual Category');
        $transaction = $this->transaction($user, $wallet, ['description' => 'ALPHA MANUAL OVERRIDE']);
        Sanctum::actingAs($user);
        $this->mockProviderResponse($aiCategory->id, 0.7, true, 'AI initial suggestion.');

        $this->postJson("/api/transactions/{$transaction->id}/ai-category-suggestion")->assertOk();

        $this->patchJson("/api/transactions/{$transaction->id}/category/correct", [
            'category_id' => $manualCategory->id,
        ])->assertOk()
            ->assertJsonPath('data.category_id', $manualCategory->id)
            ->assertJsonPath('data.suggested_category_id', null);
    }

    public function test_repeated_request_uses_cached_result(): void
    {
        [$user, $wallet, $category] = $this->fixtureWithCategory('Belanja');
        $transaction = $this->transaction($user, $wallet, ['description' => 'ALPHA CACHE SAMPLE']);
        Sanctum::actingAs($user);

        $this->mock(AiProviderClientInterface::class, function ($mock) use ($category) {
            $mock->shouldReceive('categorize')
                ->once()
                ->andReturn($this->responseJson($category->id, 0.7, true, 'Cached suggestion.'));
        });

        $this->postJson("/api/transactions/{$transaction->id}/ai-category-suggestion")->assertOk();
        $this->postJson("/api/transactions/{$transaction->id}/ai-category-suggestion")
            ->assertOk()
            ->assertJsonPath('data.suggestion.cached', true)
            ->assertJsonPath('data.validation_status', AiCategorySuggestion::STATUS_CACHED);
    }

    public function test_batch_request_item_limit_is_enforced(): void
    {
        [$user] = $this->fixture();
        Sanctum::actingAs($user);

        $this->postJson('/api/transactions/ai-categorize-pending', [
            'limit' => 26,
        ])->assertUnprocessable();
    }

    public function test_ai_failure_does_not_block_manual_categorization(): void
    {
        [$user, $wallet, $category] = $this->fixtureWithCategory('Belanja');
        $transaction = $this->transaction($user, $wallet, ['description' => 'ALPHA PROVIDER DOWN']);
        Sanctum::actingAs($user);
        $this->mockProviderException('provider_http_error');

        $this->postJson("/api/transactions/{$transaction->id}/ai-category-suggestion")->assertOk();

        $this->patchJson("/api/transactions/{$transaction->id}/category", [
            'category_id' => $category->id,
        ])->assertOk()
            ->assertJsonPath('data.category_id', $category->id);
    }

    private function fixture(): array
    {
        $user = User::factory()->create();
        $wallet = Wallet::create([
            'user_id' => $user->id,
            'name' => 'AI Test Wallet',
            'type' => 'cash',
            'currency' => 'IDR',
            'opening_balance' => 0,
            'current_balance' => 0,
            'is_active' => true,
        ]);

        return [$user, $wallet];
    }

    private function fixtureWithCategory(string $name): array
    {
        [$user, $wallet] = $this->fixture();

        return [$user, $wallet, $this->category($user, $name)];
    }

    private function category(User $user, string $name, string $type = 'expense'): Category
    {
        return Category::create([
            'user_id' => $user->id,
            'name' => $name,
            'type' => $type,
            'icon' => 'tag',
            'color' => '#3B82F6',
            'is_default' => false,
        ]);
    }

    private function transaction(User $user, Wallet $wallet, array $overrides = []): Transaction
    {
        return Transaction::create(array_merge([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'type' => 'expense',
            'amount' => 50000,
            'fee' => 0,
            'currency' => 'IDR',
            'merchant' => null,
            'description' => 'ALPHA UNCLEAR TRANSACTION',
            'note' => 'ALPHA UNCLEAR TRANSACTION',
            'happened_at' => '2026-07-15 10:00:00',
            'source' => 'bank_csv',
            'external_transaction_id' => uniqid('ai-', true),
            'sanitized_description' => null,
            'normalized_merchant' => null,
            'normalized_description' => null,
            'categorization_status' => 'review_required',
            'category_source' => 'review_required',
        ], $overrides));
    }

    private function mockProviderResponse(?int $categoryId, float $confidence, bool $needsReview, string $reason): void
    {
        $this->mockRawProviderResponse($this->responseJson($categoryId, $confidence, $needsReview, $reason));
    }

    private function mockRawProviderResponse(string $response): void
    {
        $this->mock(AiProviderClientInterface::class, function ($mock) use ($response) {
            $mock->shouldReceive('categorize')
                ->once()
                ->andReturn($response);
        });
    }

    private function mockProviderException(string $errorCode): void
    {
        $this->mock(AiProviderClientInterface::class, function ($mock) use ($errorCode) {
            $mock->shouldReceive('categorize')
                ->once()
                ->andThrow(new AiProviderException($errorCode));
        });
    }

    private function responseJson(?int $categoryId, float $confidence, bool $needsReview, string $reason): string
    {
        return json_encode([
            'category_id' => $categoryId,
            'confidence' => $confidence,
            'needs_review' => $needsReview,
            'reason' => $reason,
        ], JSON_THROW_ON_ERROR);
    }
}
