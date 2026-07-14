<?php

namespace App\Services;

use App\Models\BankConnection;
use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class BrankasService
{
    public function providers(): array
    {
        $mode = $this->isConfigured() ? 'sandbox' : 'mock';
        $supportedBankCodes = $this->isConfigured() ? $this->supportedBankCodes() : null;

        return collect(config('brankas.providers'))
            ->map(function (array $provider) use ($mode, $supportedBankCodes) {
                $isAvailable = $supportedBankCodes === null
                    || in_array($provider['brankas_code'], $supportedBankCodes, true);

                return [
                    'code' => $provider['code'],
                    'brankas_code' => $provider['brankas_code'],
                    'name' => $provider['name'],
                    'mode' => $mode,
                    'status' => $isAvailable ? 'available' : 'unavailable',
                    'description' => !$isAvailable
                        ? 'Provider ini belum aktif di Brankas sandbox untuk API key ini.'
                        : ($mode === 'mock'
                        ? 'Mock Brankas sandbox fallback untuk demo consent dan import mutasi.'
                        : 'Brankas sandbox account linking provider.'),
                ];
            })
            ->values()
            ->all();
    }

    public function startConnection(BankConnection $connection, User $user, string $redirectTo): array
    {
        if (!$this->isConfigured()) {
            return $this->mockStartConnection($connection);
        }

        $provider = $this->provider($connection->provider_code);
        $payload = [
            'country' => $provider['country'] ?? 'ID',
            'bank_codes' => [$provider['brankas_code']],
            'bank_selected' => $provider['brankas_code'],
            'external_id' => $connection->consent_state,
            'app_redirect_uri' => $this->callbackUrlForConnection($connection),
            'app_redirect_error_uri' => $this->appendQuery(
                $this->callbackUrlForConnection($connection),
                ['status' => 'failed'],
            ),
            'organization_display_name' => config('app.name', 'Fiscal Architect'),
            'start_date' => now()->subDays(30)->toDateString(),
            'end_date' => now()->toDateString(),
            'include_balance' => true,
            'remember_me' => false,
        ];

        $data = $this->http()
            ->post($this->path('start_connection'), $payload)
            ->throw()
            ->json();

        $redirectUrl = data_get($data, 'redirect_url')
            ?? data_get($data, 'redirect_uri')
            ?? data_get($data, 'authorization_url')
            ?? data_get($data, 'url');

        if (!$redirectUrl) {
            throw new RuntimeException('Brankas response tidak berisi redirect_url.');
        }

        return [
            'mode' => 'brankas',
            'session_id' => data_get($data, 'statement_id')
                ?? data_get($data, 'session_id')
                ?? data_get($data, 'id'),
            'redirect_url' => $redirectUrl,
            'raw' => $data,
        ];
    }

    public function handleCallback(Request $request, BankConnection $connection): array
    {
        if (!$this->isConfigured() || $connection->mode === 'mock') {
            return $this->mockCallback($connection);
        }

        $statementId = $this->statementIdFromCallback($request, $connection);
        $data = $this->retrieveStatementPayload($connection, $statementId);
        $statement = $this->firstStatement($data);
        $account = $this->firstAccount($statement);
        $balance = $this->balanceFromStatement($statement, $connection);
        $transactions = $this->transactionsFromStatement($statement);

        return [
            'mode' => 'brankas',
            'external_connection_id' => data_get($statement, 'statement_id') ?? $statementId,
            'external_account_id' => data_get($account, 'account_id')
                ?? data_get($account, 'account_number')
                ?? $statementId,
            'provider_name' => $connection->provider_name,
            'account_holder_name' => data_get($account, 'holder_name') ?? $connection->user->name,
            'account_number_masked' => $this->maskAccountNumber(
                data_get($account, 'account_number') ?? ''
            ),
            'expires_at' => now()->addDays(90),
            'balance' => $balance,
            'transactions' => $transactions,
            'raw' => [
                'statement_id' => data_get($statement, 'statement_id') ?? $statementId,
                'statement_status' => data_get($statement, 'status'),
                'statement' => $this->redactSensitive($statement),
                'statement_count' => count($transactions),
            ],
        ];
    }

    public function getBalance(
        BankConnection $connection,
        ?string $accountId = null,
        ?string $accessToken = null
    ): array {
        if (!$this->isConfigured() || $connection->mode === 'mock') {
            return $this->mockBalance($connection->provider_code);
        }

        $data = $this->retrieveStatementPayload(
            $connection,
            $accountId ?: $connection->external_connection_id ?: $connection->consent_session_id,
        );

        return $this->balanceFromStatement($this->firstStatement($data), $connection);
    }

    public function getStatement(
        BankConnection $connection,
        ?string $accountId = null,
        ?string $accessToken = null
    ): array {
        if (!$this->isConfigured() || $connection->mode === 'mock') {
            return $this->mockStatement($connection->provider_code);
        }

        $data = $this->retrieveStatementPayload(
            $connection,
            $accountId ?: $connection->external_connection_id ?: $connection->consent_session_id,
        );

        return $this->transactionsFromStatement($this->firstStatement($data));
    }

    public function syncConnection(BankConnection $connection): array
    {
        if ($this->isConfigured() && $connection->mode !== 'mock') {
            $data = $this->retrieveStatementPayload(
                $connection,
                $connection->external_connection_id ?: $connection->consent_session_id,
            );
            $statement = $this->firstStatement($data);

            return [
                'balance' => $this->balanceFromStatement($statement, $connection),
                'transactions' => $this->transactionsFromStatement($statement),
            ];
        }

        return [
            'balance' => $this->getBalance($connection),
            'transactions' => $this->getStatement($connection),
        ];
    }

    public function revokeConnection(BankConnection $connection): array
    {
        if (!$this->isConfigured() || $connection->mode === 'mock') {
            return [
                'mode' => 'mock',
                'revoked' => true,
            ];
        }

        return [
            'mode' => 'brankas',
            'revoked' => true,
            'raw' => [
                'local_only' => true,
                'reason' => 'Brankas Statement does not require storing user bank credentials in this app.',
            ],
        ];
    }

    public function isConfigured(): bool
    {
        return filled(config('brankas.api_key'))
            && filled(config('brankas.base_url'))
            && filled(config('brankas.callback_url'));
    }

    private function http(?string $accessToken = null): PendingRequest
    {
        $request = Http::baseUrl((string) config('brankas.base_url'))
            ->acceptJson()
            ->asJson()
            ->withHeaders([
                'x-api-key' => (string) config('brankas.api_key'),
            ]);

        if (filled(config('brankas.client_id'))) {
            $request->withHeaders([
                'x-client-id' => (string) config('brankas.client_id'),
            ]);
        }

        if ($accessToken) {
            $request->withToken($accessToken);
        }

        return $request;
    }

    private function path(string $key, array $replace = []): string
    {
        $path = config("brankas.paths.$key");

        foreach ($replace as $name => $value) {
            $path = str_replace('{' . $name . '}', (string) $value, $path);
        }

        return $path;
    }

    private function callbackUrl(): string
    {
        return (string) config('brankas.callback_url');
    }

    private function callbackUrlForConnection(BankConnection $connection): string
    {
        return $this->appendQuery($this->callbackUrl(), [
            'state' => $connection->consent_state,
        ]);
    }

    private function appendQuery(string $url, array $parameters): string
    {
        $query = http_build_query($parameters);

        if ($query === '') {
            return $url;
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . $query;
    }

    private function provider(string $providerCode): array
    {
        $provider = config("brankas.providers.$providerCode");

        if (!$provider) {
            throw new RuntimeException('Provider bank tidak tersedia.');
        }

        return $provider;
    }

    private function supportedBankCodes(): ?array
    {
        try {
            $data = $this->http()
                ->get($this->path('supported_banks'))
                ->throw()
                ->json() ?? [];
        } catch (\Throwable) {
            return null;
        }

        if (isset($data['bank_codes']) && is_array($data['bank_codes'])) {
            return $data['bank_codes'];
        }

        return collect($data)
            ->filter(fn ($items) => is_array($items))
            ->flatMap(function (array $items) {
                return collect($items)
                    ->filter(fn ($item) => is_array($item) && (bool) ($item['enabled'] ?? true))
                    ->pluck('bank_code');
            })
            ->filter()
            ->values()
            ->all();
    }

    private function statementIdFromCallback(Request $request, BankConnection $connection): string
    {
        $statementId = $request->query('statement_id')
            ?? $request->query('statementId')
            ?? $request->query('id')
            ?? $request->query('transaction_id')
            ?? $connection->consent_session_id;

        if (!$statementId) {
            throw new RuntimeException('Callback Brankas tidak berisi statement_id.');
        }

        return (string) $statementId;
    }

    private function retrieveStatementPayload(BankConnection $connection, ?string $statementId = null): array
    {
        $provider = $this->provider($connection->provider_code);
        $query = [
            'page_size' => 1,
            'bank_code' => $provider['brankas_code'],
            'skipTransactions' => false,
        ];

        if ($statementId) {
            $query['statement_ids'] = $statementId;
        } else {
            $query['external_ids'] = $connection->consent_state;
        }

        return $this->http()
            ->get($this->path('statement'), $query)
            ->throw()
            ->json() ?? [];
    }

    private function firstStatement(array $payload): array
    {
        $statement = data_get($payload, 'statements.0');

        if (!$statement && data_get($payload, 'statement_id')) {
            $statement = $payload;
        }

        if (!$statement) {
            throw new RuntimeException('Statement Brankas belum tersedia.');
        }

        return $statement;
    }

    private function firstAccount(array $statement): array
    {
        $account = data_get($statement, 'account_statements.0.account');

        if (!$account) {
            throw new RuntimeException('Statement Brankas belum berisi data rekening.');
        }

        return $account;
    }

    private function balanceFromStatement(array $statement, BankConnection $connection): array
    {
        $account = $this->firstAccount($statement);
        $balance = data_get($account, 'balance', []);

        return [
            'amount' => $this->amountValue($balance),
            'currency' => data_get($balance, 'cur') ?? 'IDR',
            'raw' => [
                'statement_id' => data_get($statement, 'statement_id'),
                'account_id' => data_get($account, 'account_id'),
                'provider_code' => $connection->provider_code,
                'balance' => $balance,
            ],
        ];
    }

    private function transactionsFromStatement(array $statement): array
    {
        return collect(data_get($statement, 'account_statements', []))
            ->flatMap(function (array $accountStatement) {
                $account = data_get($accountStatement, 'account', []);

                return collect(data_get($accountStatement, 'transactions', []))
                    ->map(function (array $transaction) use ($account) {
                        $transaction['_account'] = $account;

                        return $this->normalizeTransaction($transaction);
                    });
            })
            ->values()
            ->all();
    }

    private function amountValue(mixed $amount): float
    {
        if (is_numeric($amount)) {
            return abs((float) $amount);
        }

        if (!is_array($amount)) {
            return 0;
        }

        $decimal = data_get($amount, 'decimal.num');
        if (is_numeric($decimal)) {
            return abs((float) $decimal);
        }

        $num = data_get($amount, 'num');
        if (!is_numeric($num)) {
            return 0;
        }

        if (is_string($num) && str_contains($num, '.')) {
            return abs((float) $num);
        }

        return abs((float) $num / 100);
    }

    private function mockStartConnection(BankConnection $connection): array
    {
        return [
            'mode' => 'mock',
            'session_id' => 'mock-session-' . $connection->id,
            'redirect_url' => $this->callbackUrl() . '?' . http_build_query([
                'state' => $connection->consent_state,
                'code' => 'mock-code-' . $connection->id,
                'status' => 'success',
            ]),
            'raw' => [
                'provider_code' => $connection->provider_code,
                'fallback' => true,
            ],
        ];
    }

    private function mockCallback(BankConnection $connection): array
    {
        $provider = $this->provider($connection->provider_code);
        $accountNumber = '8800' . str_pad((string) $connection->id, 6, '0', STR_PAD_LEFT);

        return [
            'mode' => 'mock',
            'external_connection_id' => 'mock-connection-' . $connection->id,
            'external_account_id' => 'mock-account-' . $connection->id,
            'provider_name' => $provider['name'],
            'account_holder_name' => $connection->user->name,
            'account_number_masked' => $this->maskAccountNumber($accountNumber),
            'access_token' => 'mock-access-' . Str::random(48),
            'refresh_token' => 'mock-refresh-' . Str::random(48),
            'expires_at' => now()->addDays(90),
            'balance' => $this->mockBalance($connection->provider_code),
            'transactions' => $this->mockStatement($connection->provider_code),
            'raw' => [
                'fallback' => true,
                'provider_code' => $connection->provider_code,
            ],
        ];
    }

    private function mockBalance(string $providerCode): array
    {
        $balances = [
            'bri' => 4750000,
            'mandiri' => 6250000,
            'bca' => 5500000,
        ];

        return [
            'amount' => $balances[$providerCode] ?? 4500000,
            'currency' => 'IDR',
            'raw' => [
                'fallback' => true,
                'provider_code' => $providerCode,
            ],
        ];
    }

    private function mockStatement(string $providerCode): array
    {
        $prefix = strtoupper($providerCode);

        return [
            [
                'external_transaction_id' => "{$prefix}-BRANKAS-MOCK-001",
                'type' => 'income',
                'category_name' => 'Gaji',
                'amount' => 3000000,
                'fee' => 0,
                'merchant' => 'Payroll Company',
                'note' => 'Gaji bulanan dari Brankas mock',
                'reference_code' => "{$prefix}-PAYROLL-001",
                'happened_at' => now()->startOfMonth()->addDays(2)->setTime(9, 0)->format('Y-m-d H:i:s'),
                'raw_payload' => ['fallback' => true],
            ],
            [
                'external_transaction_id' => "{$prefix}-BRANKAS-MOCK-002",
                'type' => 'expense',
                'category_name' => 'Makanan',
                'amount' => 85000,
                'fee' => 0,
                'merchant' => 'Restoran Nusantara',
                'note' => 'Makan siang',
                'reference_code' => "{$prefix}-FOOD-002",
                'happened_at' => now()->startOfMonth()->addDays(4)->setTime(12, 30)->format('Y-m-d H:i:s'),
                'raw_payload' => ['fallback' => true],
            ],
            [
                'external_transaction_id' => "{$prefix}-BRANKAS-MOCK-003",
                'type' => 'expense',
                'category_name' => 'Transport',
                'amount' => 45000,
                'fee' => 0,
                'merchant' => 'Transport Online',
                'note' => 'Perjalanan harian',
                'reference_code' => "{$prefix}-TRP-003",
                'happened_at' => now()->startOfMonth()->addDays(7)->setTime(18, 15)->format('Y-m-d H:i:s'),
                'raw_payload' => ['fallback' => true],
            ],
            [
                'external_transaction_id' => "{$prefix}-BRANKAS-MOCK-004",
                'type' => 'expense',
                'category_name' => 'Tagihan',
                'amount' => 125000,
                'fee' => 2500,
                'merchant' => 'PLN',
                'note' => 'Pembayaran listrik',
                'reference_code' => "{$prefix}-BILL-004",
                'happened_at' => now()->startOfMonth()->addDays(10)->setTime(20, 0)->format('Y-m-d H:i:s'),
                'raw_payload' => ['fallback' => true],
            ],
        ];
    }

    private function normalizeTransaction(array $item): array
    {
        $amount = $this->amountValue(
            data_get($item, 'amount')
            ?? data_get($item, 'transaction_amount')
            ?? data_get($item, 'transaction_amount.amount')
            ?? 0
        );

        $direction = strtolower((string) (
            data_get($item, 'direction')
            ?? data_get($item, 'type')
            ?? data_get($item, 'transaction_type')
            ?? 'debit'
        ));

        $type = in_array($direction, ['credit', 'income', 'deposit'], true)
            ? 'income'
            : 'expense';

        $descriptor = data_get($item, 'descriptor')
            ?? data_get($item, 'description')
            ?? data_get($item, 'note');

        $baseExternalId = data_get($item, 'transaction_id')
            ?? data_get($item, 'id')
            ?? data_get($item, 'account_transaction_hash')
            ?? data_get($item, 'reference_id')
            ?? data_get($item, 'reference_code');

        $externalId = $baseExternalId
            ? sha1((string) data_get($item, '_account.account_number') . '|' . (string) $baseExternalId)
            : sha1(json_encode([
                data_get($item, '_account.account_number'),
                data_get($item, 'date'),
                $direction,
                $amount,
                $descriptor,
            ]));

        return [
            'external_transaction_id' => $externalId,
            'type' => $type,
            'category_name' => $type === 'income' ? 'Income Bank' : 'Expense Bank',
            'amount' => $amount,
            'fee' => (float) (data_get($item, 'fee') ?? 0),
            'merchant' => data_get($item, 'merchant.name') ?? data_get($item, 'merchant') ?? $descriptor,
            'note' => $descriptor,
            'reference_code' => $baseExternalId,
            'happened_at' => (data_get($item, 'date') ?? data_get($item, 'local_time'))
                ? Carbon::parse(data_get($item, 'date') ?? data_get($item, 'local_time'))->format('Y-m-d H:i:s')
                : now()->format('Y-m-d H:i:s'),
            'raw_payload' => $item,
        ];
    }

    private function maskAccountNumber(string $accountNumber): string
    {
        if (!$accountNumber) {
            return '****0000';
        }

        return '****' . substr($accountNumber, -4);
    }

    private function redactSensitive(array $data): array
    {
        foreach (['access_token', 'refresh_token', 'token'] as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = '[redacted]';
            }
        }

        return $data;
    }
}
