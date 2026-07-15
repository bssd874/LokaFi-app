<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\StellarWallet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_create_invoice(): void
    {
        $user = User::factory()->create();
        $publicKey = $this->connectWallet($user, 'A');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/invoices', $this->invoicePayload([
            'recipient_public_key' => $publicKey,
            'fiat_amount' => 125000,
        ]));

        $response->assertCreated()
            ->assertJsonPath('data.user_id', $user->id)
            ->assertJsonPath('data.fiat_currency', 'IDR')
            ->assertJsonPath('data.fiat_amount', '125000.00')
            ->assertJsonPath('data.demo_exchange_rate', '2500.00000000')
            ->assertJsonPath('data.stellar_asset_code', 'XLM')
            ->assertJsonPath('data.stellar_amount', '50.0000000')
            ->assertJsonPath('data.recipient_public_key', $publicKey)
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('invoices', [
            'user_id' => $user->id,
            'recipient_public_key' => $publicKey,
            'payment_memo' => $response->json('data.payment_memo'),
            'status' => 'pending',
        ]);
    }

    public function test_invoice_ownership_restrictions_are_enforced(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $invoice = $this->invoiceFor($owner);

        Sanctum::actingAs($otherUser);

        $this->getJson("/api/invoices/{$invoice->id}")->assertForbidden();
        $this->patchJson("/api/invoices/{$invoice->id}", [
            'description' => 'Updated by wrong user',
        ])->assertForbidden();
        $this->deleteJson("/api/invoices/{$invoice->id}")->assertForbidden();
    }

    public function test_public_invoice_can_be_retrieved_by_uuid(): void
    {
        $user = User::factory()->create(['name' => 'Merchant Demo']);
        $invoice = $this->invoiceFor($user, [
            'customer_name' => 'Customer Demo',
        ]);

        $this->getJson("/api/public/invoices/{$invoice->uuid}")
            ->assertOk()
            ->assertJsonPath('data.uuid', $invoice->uuid)
            ->assertJsonPath('data.user.name', 'Merchant Demo')
            ->assertJsonPath('data.customer_name', 'Customer Demo')
            ->assertJsonPath('data.status', 'pending');
    }

    public function test_invoice_creation_rejects_invalid_public_key(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/invoices', $this->invoicePayload([
            'recipient_public_key' => 'not-a-stellar-key',
        ]))->assertUnprocessable();
    }

    public function test_invoice_creation_rejects_public_key_not_owned_by_merchant(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherPublicKey = $this->connectWallet($otherUser, 'B');
        Sanctum::actingAs($user);

        $this->postJson('/api/invoices', $this->invoicePayload([
            'recipient_public_key' => $otherPublicKey,
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors(['recipient_public_key']);
    }

    public function test_invoice_creation_rejects_invalid_amount(): void
    {
        $user = User::factory()->create();
        $publicKey = $this->connectWallet($user, 'C');
        Sanctum::actingAs($user);

        $this->postJson('/api/invoices', $this->invoicePayload([
            'recipient_public_key' => $publicKey,
            'fiat_amount' => 0,
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors(['fiat_amount']);
    }

    public function test_expired_invoice_is_marked_expired_on_public_retrieval(): void
    {
        $user = User::factory()->create();
        $invoice = $this->invoiceFor($user, [
            'status' => 'pending',
            'expires_at' => now()->subMinute(),
        ]);

        $this->getJson("/api/public/invoices/{$invoice->uuid}")
            ->assertOk()
            ->assertJsonPath('data.status', 'expired');

        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'status' => 'expired',
        ]);
    }

    public function test_unauthenticated_user_cannot_update_invoice(): void
    {
        $invoice = $this->invoiceFor(User::factory()->create());

        $this->patchJson("/api/invoices/{$invoice->id}", [
            'description' => 'Unauthorized update',
        ])->assertUnauthorized();
    }

    public function test_invoice_uuid_and_payment_memo_are_unique(): void
    {
        $user = User::factory()->create();
        $publicKey = $this->connectWallet($user, 'D');
        Sanctum::actingAs($user);

        $first = $this->postJson('/api/invoices', $this->invoicePayload([
            'recipient_public_key' => $publicKey,
            'description' => 'Invoice one',
        ]))->assertCreated();

        $second = $this->postJson('/api/invoices', $this->invoicePayload([
            'recipient_public_key' => $publicKey,
            'description' => 'Invoice two',
        ]))->assertCreated();

        $this->assertNotSame($first->json('data.uuid'), $second->json('data.uuid'));
        $this->assertNotSame($first->json('data.payment_memo'), $second->json('data.payment_memo'));
        $this->assertDatabaseCount('invoices', 2);
    }

    private function invoicePayload(array $overrides = []): array
    {
        return array_merge([
            'customer_name' => 'Customer Demo',
            'customer_email' => 'customer@example.com',
            'description' => 'Jasa desain poster kampus',
            'fiat_amount' => 75000,
            'recipient_public_key' => $this->publicKey('Z'),
            'expires_at' => now()->addDay()->toDateTimeString(),
        ], $overrides);
    }

    private function invoiceFor(User $user, array $overrides = []): Invoice
    {
        $publicKey = $overrides['recipient_public_key'] ?? $this->connectWallet($user, 'E');

        return Invoice::create(array_merge([
            'uuid' => (string) fake()->uuid(),
            'user_id' => $user->id,
            'customer_name' => 'Customer Demo',
            'customer_email' => 'customer@example.com',
            'description' => 'Invoice fixture',
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
}
