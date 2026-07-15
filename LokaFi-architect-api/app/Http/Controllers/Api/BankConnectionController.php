<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankConnection;
use App\Models\Wallet;
use App\Services\BrankasTransactionImportService;
use App\Services\BrankasService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class BankConnectionController extends Controller
{
    public function __construct(
        private readonly BrankasService $brankasService,
        private readonly BrankasTransactionImportService $transactionImportService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $connections = $request->user()
            ->bankConnections()
            ->with('wallets')
            ->latest()
            ->get();

        return response()->json([
            'message' => 'Data koneksi bank berhasil diambil',
            'data' => $connections,
        ]);
    }

    public function start(Request $request): JsonResponse
    {
        $providerCodes = collect(config('brankas.providers'))->keys()->all();

        $data = $request->validate([
            'provider_code' => ['required', 'string', Rule::in($providerCodes)],
            'redirect_to' => ['nullable', 'url'],
        ]);

        $provider = config("brankas.providers.{$data['provider_code']}");
        $redirectTo = $data['redirect_to'] ?? config('app.frontend_url') ?? config('app.url');

        $result = DB::transaction(function () use ($request, $data, $provider, $redirectTo) {
            $connection = $request->user()->bankConnections()->create([
                'provider_code' => $provider['code'],
                'provider_name' => $provider['name'],
                'status' => 'pending',
                'mode' => $this->brankasService->isConfigured() ? 'brankas' : 'mock',
                'consent_state' => (string) Str::uuid(),
                'metadata' => [
                    'frontend_redirect_url' => $redirectTo,
                    'scope' => ['account_balance', 'transaction_history'],
                ],
            ]);

            $session = $this->brankasService->startConnection(
                connection: $connection,
                user: $request->user(),
                redirectTo: $redirectTo,
            );

            $connection->update([
                'mode' => $session['mode'],
                'consent_session_id' => $session['session_id'] ?? null,
                'metadata' => array_merge($connection->metadata ?? [], [
                    'start_response' => $session['raw'] ?? null,
                ]),
            ]);

            return [
                'connection' => $connection->fresh(),
                'redirect_url' => $session['redirect_url'],
                'mode' => $session['mode'],
            ];
        });

        return response()->json([
            'message' => $result['mode'] === 'mock'
                ? 'Mock Brankas consent session berhasil dibuat'
                : 'Brankas consent session berhasil dibuat',
            'data' => $result,
        ], 201);
    }

    public function connect(Request $request): JsonResponse
    {
        return $this->start($request);
    }

    public function callback(Request $request): RedirectResponse|JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'state' => ['required', 'string'],
            'code' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'error' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Callback Brankas tidak valid',
                'errors' => $validator->errors(),
            ], 422);
        }

        $connection = BankConnection::with('user')
            ->where('consent_state', $request->query('state'))
            ->first();

        if (!$connection) {
            return response()->json([
                'message' => 'State Brankas tidak ditemukan',
            ], 404);
        }

        if ($request->query('error') || $request->query('status') === 'failed') {
            $message = $request->query('error') ?: 'Consent bank gagal atau dibatalkan';

            $connection->update([
                'status' => 'failed',
                'error_message' => $message,
            ]);

            return $this->redirectToFrontend($connection, 'failed', $message);
        }

        try {
            $result = DB::transaction(function () use ($request, $connection) {
                $callback = $this->brankasService->handleCallback($request, $connection);
                $balance = $callback['balance'];
                $transactions = $callback['transactions'];
                $wallet = $this->createOrUpdateWallet($connection, $callback, $balance, $transactions);
                $importResult = $this->transactionImportService->import($connection, $wallet, $transactions);

                $connection->update([
                    'status' => 'connected',
                    'mode' => $callback['mode'],
                    'account_holder_name' => $callback['account_holder_name'],
                    'account_number_masked' => $callback['account_number_masked'],
                    'external_connection_id' => $callback['external_connection_id'],
                    'external_account_id' => $callback['external_account_id'],
                    'access_token_encrypted' => isset($callback['access_token'])
                        ? encrypt($callback['access_token'])
                        : null,
                    'refresh_token_encrypted' => isset($callback['refresh_token'])
                        ? encrypt($callback['refresh_token'])
                        : null,
                    'expires_at' => $callback['expires_at'],
                    'last_synced_at' => now(),
                    'error_message' => null,
                    'metadata' => array_merge($connection->metadata ?? [], [
                        'callback_response' => $callback['raw'] ?? null,
                    ]),
                ]);

                $wallet->update([
                    'current_balance' => $balance['amount'],
                    'currency' => $balance['currency'],
                    'connection_status' => 'connected',
                    'last_synced_at' => now(),
                ]);

                return [
                    'connection' => $connection->fresh()->load('wallets'),
                    'wallet' => $wallet->fresh(),
                    'imported_transactions_count' => $importResult['imported_count'],
                    'skipped_transactions_count' => $importResult['skipped_count'],
                    'failed_transactions_count' => $importResult['error_count'],
                ];
            });

            return $this->redirectToFrontend(
                $connection->fresh(),
                'success',
                'Koneksi bank berhasil',
                $result['imported_transactions_count'],
            );
        } catch (\Throwable $exception) {
            Log::error('Brankas callback failed', [
                'connection_id' => $connection->id,
                'message' => $exception->getMessage(),
            ]);

            $connection->update([
                'status' => 'failed',
                'error_message' => 'Gagal memproses callback Brankas',
            ]);

            return $this->redirectToFrontend(
                $connection,
                'failed',
                'Gagal memproses callback Brankas',
            );
        }
    }

    public function sync(Request $request, BankConnection $bankConnection): JsonResponse
    {
        $this->ensureConnectionBelongsToUser($request, $bankConnection);

        if ($bankConnection->status !== 'connected') {
            return response()->json([
                'message' => 'Koneksi bank tidak aktif',
            ], 422);
        }

        $result = DB::transaction(function () use ($bankConnection) {
            $sync = $this->brankasService->syncConnection($bankConnection);
            $wallet = Wallet::where('bank_connection_id', $bankConnection->id)->firstOrFail();
            $importResult = $this->transactionImportService->import($bankConnection, $wallet, $sync['transactions']);

            $wallet->update([
                'current_balance' => $sync['balance']['amount'],
                'currency' => $sync['balance']['currency'],
                'connection_status' => 'connected',
                'sync_source' => 'brankas',
                'last_synced_at' => now(),
            ]);

            $bankConnection->update([
                'status' => 'connected',
                'last_synced_at' => now(),
                'error_message' => null,
                'metadata' => array_merge($bankConnection->metadata ?? [], [
                    'last_sync' => [
                        'mode' => $bankConnection->mode,
                        'balance' => $sync['balance']['raw'] ?? null,
                        'statement_count' => count($sync['transactions']),
                        'import_result' => $importResult,
                    ],
                ]),
            ]);

            return [
                'connection' => $bankConnection->fresh()->load('wallets'),
                'wallet' => $wallet->fresh(),
                'imported_transactions_count' => $importResult['imported_count'],
                'skipped_transactions_count' => $importResult['skipped_count'],
                'failed_transactions_count' => $importResult['error_count'],
            ];
        });

        return response()->json([
            'message' => 'Sinkronisasi rekening berhasil',
            'data' => $result,
        ]);
    }

    public function destroy(Request $request, BankConnection $bankConnection): JsonResponse
    {
        $this->ensureConnectionBelongsToUser($request, $bankConnection);

        DB::transaction(function () use ($bankConnection) {
            $this->brankasService->revokeConnection($bankConnection);

            $bankConnection->update([
                'status' => 'revoked',
                'error_message' => null,
            ]);

            Wallet::where('bank_connection_id', $bankConnection->id)->update([
                'connection_status' => 'revoked',
            ]);
        });

        return response()->json([
            'message' => 'Koneksi bank berhasil dicabut',
        ]);
    }

    private function createOrUpdateWallet(
        BankConnection $connection,
        array $callback,
        array $balance,
        array $transactions,
    ): Wallet {
        $netImportedAmount = collect($transactions)->sum(function (array $transaction) {
            if ($transaction['type'] === 'income') {
                return $transaction['amount'];
            }

            return -1 * ($transaction['amount'] + ($transaction['fee'] ?? 0));
        });

        $openingBalance = max($balance['amount'] - $netImportedAmount, 0);

        return Wallet::updateOrCreate(
            ['bank_connection_id' => $connection->id],
            [
                'user_id' => $connection->user_id,
                'name' => $connection->provider_name . ' ' . $callback['account_number_masked'],
                'type' => 'bank',
                'currency' => $balance['currency'],
                'opening_balance' => $openingBalance,
                'current_balance' => $balance['amount'],
                'is_active' => true,
                'provider_code' => $connection->provider_code,
                'account_number_masked' => $callback['account_number_masked'],
                'connection_status' => 'connected',
                'sync_source' => 'brankas',
                'last_synced_at' => now(),
            ],
        );
    }

    private function redirectToFrontend(
        BankConnection $connection,
        string $status,
        string $message,
        int $importedCount = 0,
    ): RedirectResponse {
        $baseUrl = $connection->metadata['frontend_redirect_url']
            ?? config('app.frontend_url')
            ?? config('app.url');

        $query = http_build_query([
            'bank_connection_status' => $status,
            'message' => $message,
            'imported' => $importedCount,
        ]);

        return redirect()->away(rtrim($baseUrl, '/') . '/bank-connections?' . $query);
    }

    private function ensureConnectionBelongsToUser(Request $request, BankConnection $bankConnection): void
    {
        if ($bankConnection->user_id !== $request->user()->id) {
            abort(403, 'Kamu tidak punya akses ke koneksi bank ini');
        }
    }
}
