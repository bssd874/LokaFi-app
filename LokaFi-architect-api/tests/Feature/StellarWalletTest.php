<?php

namespace Tests\Feature;

use App\Models\StellarWallet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StellarWalletTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_store_testnet_freighter_public_key(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $publicKey = $this->publicKey('A');

        $response = $this->postJson('/api/stellar/wallet', [
            'public_key' => $publicKey,
            'network' => 'testnet',
            'wallet_provider' => 'freighter',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.user_id', $user->id)
            ->assertJsonPath('data.public_key', $publicKey)
            ->assertJsonPath('data.network', 'testnet')
            ->assertJsonPath('data.wallet_provider', 'freighter');

        $this->assertDatabaseHas('stellar_wallets', [
            'user_id' => $user->id,
            'public_key' => $publicKey,
            'network' => 'testnet',
            'wallet_provider' => 'freighter',
        ]);

        foreach (['secret_key', 'private_key', 'mnemonic', 'recovery_phrase'] as $column) {
            $this->assertFalse(Schema::hasColumn('stellar_wallets', $column));
        }
    }

    public function test_user_gets_only_their_stellar_wallet(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $wallet = StellarWallet::create([
            'user_id' => $user->id,
            'public_key' => $this->publicKey('B'),
            'network' => 'testnet',
            'wallet_provider' => 'freighter',
            'connected_at' => now(),
        ]);

        StellarWallet::create([
            'user_id' => $otherUser->id,
            'public_key' => $this->publicKey('C'),
            'network' => 'testnet',
            'wallet_provider' => 'freighter',
            'connected_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/stellar/wallet')
            ->assertOk()
            ->assertJsonPath('data.id', $wallet->id)
            ->assertJsonPath('data.user_id', $user->id);
    }

    public function test_store_rejects_non_testnet_network_and_secret_values(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/stellar/wallet', [
            'public_key' => $this->publicKey('D'),
            'network' => 'mainnet',
            'wallet_provider' => 'freighter',
        ])->assertUnprocessable();

        $this->postJson('/api/stellar/wallet', [
            'public_key' => $this->publicKey('E'),
            'network' => 'testnet',
            'wallet_provider' => 'freighter',
            'secret_key' => 'S' . str_repeat('A', 55),
        ])->assertUnprocessable();

        $this->assertDatabaseCount('stellar_wallets', 0);
    }

    public function test_store_updates_existing_local_session_instead_of_creating_duplicate(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/stellar/wallet', [
            'public_key' => $this->publicKey('F'),
            'network' => 'testnet',
            'wallet_provider' => 'freighter',
        ])->assertCreated();

        $secondPublicKey = $this->publicKey('G');

        $this->postJson('/api/stellar/wallet', [
            'public_key' => $secondPublicKey,
            'network' => 'testnet',
            'wallet_provider' => 'freighter',
        ])->assertCreated()
            ->assertJsonPath('data.public_key', $secondPublicKey);

        $this->assertDatabaseCount('stellar_wallets', 1);
        $this->assertDatabaseHas('stellar_wallets', [
            'user_id' => $user->id,
            'public_key' => $secondPublicKey,
        ]);
    }

    public function test_disconnect_deletes_only_current_users_local_stellar_wallet(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        StellarWallet::create([
            'user_id' => $user->id,
            'public_key' => $this->publicKey('H'),
            'network' => 'testnet',
            'wallet_provider' => 'freighter',
            'connected_at' => now(),
        ]);

        $otherWallet = StellarWallet::create([
            'user_id' => $otherUser->id,
            'public_key' => $this->publicKey('J'),
            'network' => 'testnet',
            'wallet_provider' => 'freighter',
            'connected_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $this->deleteJson('/api/stellar/wallet')->assertOk();

        $this->assertDatabaseMissing('stellar_wallets', ['user_id' => $user->id]);
        $this->assertDatabaseHas('stellar_wallets', ['id' => $otherWallet->id]);
    }

    public function test_stellar_wallet_routes_require_authentication(): void
    {
        $this->getJson('/api/stellar/wallet')->assertUnauthorized();
        $this->postJson('/api/stellar/wallet', [])->assertUnauthorized();
        $this->deleteJson('/api/stellar/wallet')->assertUnauthorized();
    }

    private function publicKey(string $character): string
    {
        return 'G' . str_repeat($character, 55);
    }
}
