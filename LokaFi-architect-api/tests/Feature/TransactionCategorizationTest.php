<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\CategoryRule;
use App\Models\Transaction;
use App\Models\TransactionCategoryMapping;
use App\Models\User;
use App\Models\Wallet;
use App\Services\DefaultCategoryService;
use App\Services\TransactionCategorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class TransactionCategorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_user_mapping_uses_canonical_merchant_variations(): void
    {
        [$user, $wallet] = $this->fixture();
        $food = $this->category($user, 'Makanan dan Minuman', 'expense');
        Sanctum::actingAs($user);

        $first = $this->transaction($user, $wallet, [
            'description' => 'QRIS WARUNG SEDERHANA JKT 12345',
            'source' => 'bank_csv',
        ]);

        $this->patchJson("/api/transactions/{$first->id}/category/correct", [
            'category_id' => $food->id,
        ])->assertOk();

        $second = $this->transaction($user, $wallet, [
            'description' => 'TRX WARUNG SEDERHANA',
            'source' => 'bank_csv',
        ]);

        $this->getJson("/api/transactions/{$second->id}/category-suggestion")
            ->assertOk()
            ->assertJsonPath('data.category.id', $food->id)
            ->assertJsonPath('data.source', TransactionCategorizationService::SOURCE_VERIFIED_MAPPING)
            ->assertJsonPath('data.confidence', TransactionCategorizationService::CONFIDENCE_HIGH);

        $this->assertDatabaseHas('transactions', [
            'id' => $first->id,
            'normalized_description' => 'warung sederhana',
        ]);
    }

    public function test_user_rule_priority_beats_safe_default_rule(): void
    {
        [$user, $wallet] = $this->fixture();
        app(DefaultCategoryService::class)->ensureForUser($user);
        $shopping = $this->category($user, 'Belanja Khusus', 'expense');

        CategoryRule::create([
            'user_id' => $user->id,
            'category_id' => $shopping->id,
            'name' => 'Coffee goes to custom shopping',
            'match_type' => CategoryRule::MATCH_KEYWORD,
            'pattern' => 'kopi',
            'transaction_type' => 'expense',
            'priority' => 1,
            'confidence' => 'high',
            'confidence_score' => 92,
            'is_active' => true,
        ]);

        $transaction = $this->transaction($user, $wallet, [
            'description' => 'QRIS KOPI PAGI',
            'source' => 'bank_csv',
        ]);

        Sanctum::actingAs($user);

        $this->getJson("/api/transactions/{$transaction->id}/category-suggestion")
            ->assertOk()
            ->assertJsonPath('data.category.id', $shopping->id)
            ->assertJsonPath('data.source', TransactionCategorizationService::SOURCE_USER_RULE);
    }

    public function test_safe_default_rule_suggests_user_default_category(): void
    {
        [$user, $wallet] = $this->fixture();
        app(DefaultCategoryService::class)->ensureForUser($user);
        $food = $user->categories()->where('name', 'Makanan dan Minuman')->firstOrFail();
        $transaction = $this->transaction($user, $wallet, [
            'description' => 'PEMBAYARAN KOPI PAGI',
            'source' => 'ewallet_csv',
        ]);

        Sanctum::actingAs($user);

        $this->getJson("/api/transactions/{$transaction->id}/category-suggestion")
            ->assertOk()
            ->assertJsonPath('data.category.id', $food->id)
            ->assertJsonPath('data.source', TransactionCategorizationService::SOURCE_DEFAULT_RULE)
            ->assertJsonPath('data.confidence', TransactionCategorizationService::CONFIDENCE_MEDIUM);
    }

    public function test_transaction_type_mismatch_does_not_apply_rule(): void
    {
        [$user, $wallet] = $this->fixture();
        $expenseCategory = $this->category($user, 'Expense Rule', 'expense');

        CategoryRule::create([
            'user_id' => $user->id,
            'category_id' => $expenseCategory->id,
            'name' => 'Expense only bonus rule',
            'match_type' => CategoryRule::MATCH_KEYWORD,
            'pattern' => 'bonuskhusus',
            'transaction_type' => 'expense',
            'priority' => 1,
            'is_active' => true,
        ]);

        $transaction = $this->transaction($user, $wallet, [
            'type' => 'income',
            'description' => 'BONUSKHUSUS PROJECT',
            'source' => 'bank_csv',
        ]);

        Sanctum::actingAs($user);

        $this->getJson("/api/transactions/{$transaction->id}/category-suggestion")
            ->assertOk()
            ->assertJsonPath('data.category', null)
            ->assertJsonPath('data.review_required', true);
    }

    public function test_source_mismatch_does_not_apply_rule(): void
    {
        [$user, $wallet] = $this->fixture();
        $category = $this->category($user, 'Bank Only', 'expense');

        CategoryRule::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'name' => 'Bank source only',
            'match_type' => CategoryRule::MATCH_KEYWORD,
            'pattern' => 'tokokhusus',
            'transaction_type' => 'expense',
            'source' => 'bank_csv',
            'priority' => 1,
            'is_active' => true,
        ]);

        $transaction = $this->transaction($user, $wallet, [
            'description' => 'TOKOKHUSUS BELANJA',
            'source' => 'ewallet_csv',
        ]);

        Sanctum::actingAs($user);

        $this->getJson("/api/transactions/{$transaction->id}/category-suggestion")
            ->assertOk()
            ->assertJsonPath('data.category', null)
            ->assertJsonPath('data.review_required', true);
    }

    public function test_user_cannot_suggest_or_use_other_users_transaction(): void
    {
        [$user] = $this->fixture();
        [$otherUser, $otherWallet] = $this->fixture();
        $otherTransaction = $this->transaction($otherUser, $otherWallet);

        Sanctum::actingAs($user);

        $this->getJson("/api/transactions/{$otherTransaction->id}/category-suggestion")
            ->assertForbidden();
    }

    public function test_feedback_mapping_is_reused_when_reprocessing_future_transactions(): void
    {
        [$user, $wallet] = $this->fixture();
        $food = $this->category($user, 'Makanan dan Minuman', 'expense');
        Sanctum::actingAs($user);

        $first = $this->transaction($user, $wallet, [
            'description' => 'PEMBAYARAN WARUNG SEDERHANA',
            'source' => 'bank_csv',
        ]);
        $second = $this->transaction($user, $wallet, [
            'description' => 'QRIS WARUNG SEDERHANA JKT 99999',
            'source' => 'bank_csv',
        ]);

        $this->patchJson("/api/transactions/{$first->id}/category/correct", [
            'category_id' => $food->id,
        ])->assertOk();

        $this->postJson('/api/transactions/reprocess-categorization', [
            'transaction_ids' => [$second->id],
        ])->assertOk()
            ->assertJsonPath('data.updated_count', 1);

        $this->assertDatabaseHas('transactions', [
            'id' => $second->id,
            'category_id' => $food->id,
            'category_source' => TransactionCategorizationService::SOURCE_VERIFIED_MAPPING,
        ]);
    }

    public function test_duplicate_mapping_updates_existing_mapping(): void
    {
        [$user, $wallet] = $this->fixture();
        $food = $this->category($user, 'Makanan dan Minuman', 'expense');
        $shopping = $this->category($user, 'Belanja', 'expense');
        $transaction = $this->transaction($user, $wallet, [
            'description' => 'QRIS TOKO DUPLIKAT',
            'source' => 'ewallet_csv',
        ]);
        Sanctum::actingAs($user);

        $this->patchJson("/api/transactions/{$transaction->id}/category/correct", [
            'category_id' => $food->id,
        ])->assertOk();

        $this->patchJson("/api/transactions/{$transaction->id}/category/correct", [
            'category_id' => $shopping->id,
        ])->assertOk();

        $this->assertSame(1, TransactionCategoryMapping::count());
        $mapping = TransactionCategoryMapping::firstOrFail();
        $this->assertSame($shopping->id, $mapping->category_id);
        $this->assertSame(2, $mapping->usage_count);
    }

    public function test_review_required_fallback_when_no_rule_or_mapping_matches(): void
    {
        [$user, $wallet] = $this->fixture();
        $transaction = $this->transaction($user, $wallet, [
            'description' => 'UNMAPPED ALPHA BETA',
            'source' => 'bank_csv',
        ]);
        Sanctum::actingAs($user);

        $this->getJson("/api/transactions/{$transaction->id}/category-suggestion")
            ->assertOk()
            ->assertJsonPath('data.category', null)
            ->assertJsonPath('data.source', TransactionCategorizationService::SOURCE_REVIEW_REQUIRED)
            ->assertJsonPath('data.review_required', true);

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'categorization_status' => TransactionCategorizationService::STATUS_REVIEW_REQUIRED,
        ]);
    }

    public function test_service_failure_does_not_block_manual_transaction_category_update(): void
    {
        [$user, $wallet] = $this->fixture();
        $category = $this->category($user, 'Manual Category', 'expense');
        $transaction = $this->transaction($user, $wallet);
        Sanctum::actingAs($user);

        $this->mock(TransactionCategorizationService::class, function ($mock) {
            $mock->shouldReceive('correctCategory')
                ->once()
                ->andThrow(new RuntimeException('Service unavailable'));
        });

        $this->patchJson("/api/transactions/{$transaction->id}/category", [
            'category_id' => $category->id,
        ])->assertOk()
            ->assertJsonPath('data.category_id', $category->id);

        $this->assertDatabaseHas('transaction_category_labels', [
            'transaction_id' => $transaction->id,
            'category_id' => $category->id,
            'is_verified' => true,
        ]);
    }

    private function fixture(): array
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

    private function category(User $user, string $name, string $type): Category
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
            'amount' => 25000,
            'fee' => 0,
            'currency' => 'IDR',
            'merchant' => null,
            'description' => 'QRIS WARUNG TEST',
            'note' => 'QRIS WARUNG TEST',
            'happened_at' => '2026-07-15 10:00:00',
            'source' => 'bank_csv',
            'external_transaction_id' => uniqid('cat-', true),
            'sanitized_description' => null,
            'normalized_merchant' => null,
            'normalized_description' => null,
            'categorization_status' => 'unclassified',
            'category_source' => 'unclassified',
        ], $overrides));
    }
}
