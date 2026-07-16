<?php

namespace App\Console\Commands;

use App\Models\Budget;
use App\Models\Category;
use App\Models\CategoryRule;
use App\Models\Invoice;
use App\Models\StellarWallet;
use App\Models\Transaction;
use App\Models\TransactionCategoryLabel;
use App\Models\TransactionCategoryMapping;
use App\Models\User;
use App\Models\Wallet;
use App\Services\FinancialIntelligenceService;
use App\Services\TransactionImport\TransactionBalanceService;
use App\Services\TransactionImport\TransactionImportDedupeService;
use App\Services\TransactionSanitizationService;
use App\Services\TransactionTextNormalizationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PrepareVideoDemoData extends Command
{
    private const DEMO_DATASET = 'lokafi_video_demo_v1';

    private const DEMO_RUN_ID = 'LOKAFI-VIDEO-DEMO-2026';

    private const DEMO_USER_NAME = 'Boni Steven Demo';

    private const DEFAULT_EMAIL = 'demo.video@lokafi.local';

    private const DEFAULT_REFERENCE_DATE = '2026-07-15';

    private const WALLET_DEFINITIONS = [
        'Cash Wallet' => [
            'type' => 'cash',
            'opening_balance' => 2000000,
            'provider_code' => null,
            'account_number_masked' => null,
        ],
        'BCA Statement Import' => [
            'type' => 'bank',
            'opening_balance' => 4500000,
            'provider_code' => 'BCA',
            'account_number_masked' => '**** 2026',
        ],
        'GoPay Statement Import' => [
            'type' => 'ewallet',
            'opening_balance' => 750000,
            'provider_code' => 'GOPAY',
            'account_number_masked' => '**** 0726',
        ],
    ];

    private const CATEGORY_DEFINITIONS = [
        'Salary' => ['type' => 'income', 'icon' => 'briefcase-business', 'color' => '#16A34A'],
        'Sales' => ['type' => 'income', 'icon' => 'badge-dollar-sign', 'color' => '#059669'],
        'Freelance Income' => ['type' => 'income', 'icon' => 'laptop', 'color' => '#0D9488'],
        'Food and Beverage' => ['type' => 'expense', 'icon' => 'utensils', 'color' => '#EF4444'],
        'Transportation' => ['type' => 'expense', 'icon' => 'car', 'color' => '#2563EB'],
        'Shopping' => ['type' => 'expense', 'icon' => 'shopping-bag', 'color' => '#9333EA'],
        'Bills' => ['type' => 'expense', 'icon' => 'receipt', 'color' => '#F97316'],
        'Entertainment' => ['type' => 'expense', 'icon' => 'gamepad-2', 'color' => '#EAB308'],
        'Education' => ['type' => 'expense', 'icon' => 'graduation-cap', 'color' => '#0891B2'],
        'Business Operations' => ['type' => 'expense', 'icon' => 'building-2', 'color' => '#475569'],
    ];

    protected $signature = 'lokafi:demo:prepare
        {--email=demo.video@lokafi.local : Dedicated demo user email}
        {--reference-date= : Reference date (YYYY-MM-DD)}
        {--reset : Remove and recreate only the dedicated video demo user}
        {--show-credentials : Print the demo email and generated/configured password}';

    protected $description = 'Prepare an isolated, deterministic local dataset for the LokaFi hackathon video demo.';

    public function handle(
        TransactionSanitizationService $sanitizer,
        TransactionTextNormalizationService $normalizer,
        TransactionImportDedupeService $dedupeService,
        TransactionBalanceService $balanceService,
        FinancialIntelligenceService $financialIntelligence,
    ): int {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Video demo preparation is disabled outside local/testing environments.');

            return self::FAILURE;
        }

        $email = mb_strtolower(trim((string) $this->option('email')));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('The --email value must be a valid email address.');

            return self::FAILURE;
        }

        try {
            $referenceDate = $this->referenceDate();
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $existingUser = User::where('email', $email)->first();

        if ($existingUser && $existingUser->name !== self::DEMO_USER_NAME) {
            $this->error('The selected email already belongs to a non-demo user. Use a dedicated email address.');

            return self::FAILURE;
        }

        if ($existingUser && ! $this->option('reset')) {
            $this->warn('Video demo data already exists for this email. Re-run with --reset to recreate it.');

            return self::SUCCESS;
        }

        $password = trim((string) env('LOKAFI_DEMO_PASSWORD'));
        $password = $password !== '' ? $password : Str::password(20);

        $result = DB::transaction(function () use (
            $existingUser,
            $email,
            $password,
            $referenceDate,
            $sanitizer,
            $normalizer,
            $dedupeService,
            $balanceService,
        ): array {
            if ($existingUser) {
                $existingUser->delete();
            }

            $user = User::create([
                'name' => self::DEMO_USER_NAME,
                'email' => $email,
                'password' => $password,
                'timezone' => 'Asia/Jakarta',
                'base_currency' => 'IDR',
            ]);
            $user->forceFill(['email_verified_at' => $referenceDate])->save();

            $wallets = $this->createWallets($user);
            $categories = $this->createCategories($user);
            $this->createRules($user, $categories);
            $this->createMappings($user, $categories, $referenceDate);

            $transactions = $this->createTransactions(
                user: $user,
                wallets: $wallets,
                categories: $categories,
                referenceDate: $referenceDate,
                sanitizer: $sanitizer,
                normalizer: $normalizer,
                dedupeService: $dedupeService,
                balanceService: $balanceService,
            );

            $this->createBudgets($user, $categories, $referenceDate);
            $stellarPrepared = $this->createOptionalStellarState($user, $referenceDate);

            return [
                'user' => $user,
                'wallet_count' => count($wallets),
                'category_count' => count($categories),
                'transaction_count' => count($transactions),
                'stellar_prepared' => $stellarPrepared,
            ];
        });

        $summary = $financialIntelligence->summary($result['user'], [
            'start_date' => $referenceDate->startOfMonth()->toDateString(),
            'end_date' => $referenceDate->endOfMonth()->toDateString(),
            'timezone' => 'Asia/Jakarta',
        ]);

        $this->info('LokaFi video demo data prepared.');
        $this->table(['Item', 'Value'], [
            ['Demo run', self::DEMO_RUN_ID],
            ['Reference date', $referenceDate->toDateString()],
            ['Wallets', (string) $result['wallet_count']],
            ['Categories', (string) $result['category_count']],
            ['Seeded transactions', (string) $result['transaction_count']],
            ['Current-period income', $this->formatIdr($summary['summary']['total_income'])],
            ['Current-period expense', $this->formatIdr($summary['summary']['total_expense'])],
            ['Current-period net cashflow', $this->formatIdr($summary['summary']['net_cashflow'])],
        ]);

        if (! $result['stellar_prepared']) {
            $this->line('Stellar state skipped: set a valid LOKAFI_DEMO_STELLAR_PUBLIC_KEY to create a pending Testnet invoice.');
        }

        if ($this->option('show-credentials')) {
            $this->newLine();
            $this->warn('Demo credentials (shown only because --show-credentials was supplied):');
            $this->line("Email: {$email}");
            $this->line("Password: {$password}");
        }

        $this->newLine();
        $this->line('Next: import demo-data/video-demo/bank_statement_video_demo.csv from the Accounts statement import UI.');

        return self::SUCCESS;
    }

    private function referenceDate(): CarbonImmutable
    {
        $value = trim((string) ($this->option('reference-date') ?: env('LOKAFI_DEMO_REFERENCE_DATE', self::DEFAULT_REFERENCE_DATE)));

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'Asia/Jakarta');
        } catch (\Throwable) {
            $date = false;
        }

        if (! $date || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('The reference date must use the YYYY-MM-DD format.');
        }

        return $date->startOfDay();
    }

    /** @return array<string, Wallet> */
    private function createWallets(User $user): array
    {
        $wallets = [];

        foreach (self::WALLET_DEFINITIONS as $name => $definition) {
            $wallets[$name] = Wallet::create([
                'user_id' => $user->id,
                'name' => $name,
                'type' => $definition['type'],
                'currency' => 'IDR',
                'opening_balance' => $definition['opening_balance'],
                'current_balance' => $definition['opening_balance'],
                'is_active' => true,
                'provider_code' => $definition['provider_code'],
                'account_number_masked' => $definition['account_number_masked'],
                'connection_status' => 'manual',
                'sync_source' => 'manual',
                'last_synced_at' => null,
            ]);
        }

        return $wallets;
    }

    /** @return array<string, Category> */
    private function createCategories(User $user): array
    {
        $categories = [];

        foreach (self::CATEGORY_DEFINITIONS as $name => $definition) {
            $categories[$name] = Category::create([
                'user_id' => $user->id,
                'name' => $name,
                'type' => $definition['type'],
                'icon' => $definition['icon'],
                'color' => $definition['color'],
                'is_default' => false,
            ]);
        }

        return $categories;
    }

    /** @param array<string, Category> $categories */
    private function createRules(User $user, array $categories): void
    {
        $rules = [
            ['Nusantara Bento Lab', CategoryRule::MATCH_NORMALIZED_MERCHANT, 'Nusantara Bento Lab', 'expense', 'Food and Beverage', 10],
            ['MetroSwift Ride', CategoryRule::MATCH_NORMALIZED_MERCHANT, 'MetroSwift Ride', 'expense', 'Transportation', 11],
            ['PixelNest Supplies', CategoryRule::MATCH_NORMALIZED_MERCHANT, 'PixelNest Supplies', 'expense', 'Shopping', 12],
            ['Sagara Internet', CategoryRule::MATCH_NORMALIZED_MERCHANT, 'Sagara Internet', 'expense', 'Bills', 13],
            ['OrbitPlay Studio', CategoryRule::MATCH_NORMALIZED_MERCHANT, 'OrbitPlay Studio', 'expense', 'Entertainment', 14],
            ['Akademi Digital Merah Putih', CategoryRule::MATCH_NORMALIZED_MERCHANT, 'Akademi Digital Merah Putih', 'expense', 'Education', 15],
            ['Aurora Workspace', CategoryRule::MATCH_NORMALIZED_MERCHANT, 'Aurora Workspace', 'expense', 'Business Operations', 16],
            ['Cipta Karya Printing', CategoryRule::MATCH_NORMALIZED_MERCHANT, 'Cipta Karya Printing', 'expense', 'Business Operations', 17],
            ['NovaCloud Workspace', CategoryRule::MATCH_NORMALIZED_MERCHANT, 'NovaCloud Workspace', 'expense', 'Business Operations', 18],
            ['Salary income', CategoryRule::MATCH_KEYWORD, 'salary,payroll', 'income', 'Salary', 20],
            ['Sales income', CategoryRule::MATCH_KEYWORD, 'sales,order lokafi', 'income', 'Sales', 21],
            ['Freelance income', CategoryRule::MATCH_KEYWORD, 'freelance,client payout', 'income', 'Freelance Income', 22],
        ];

        foreach ($rules as [$name, $matchType, $pattern, $type, $categoryName, $priority]) {
            CategoryRule::create([
                'user_id' => $user->id,
                'category_id' => $categories[$categoryName]->id,
                'name' => "VIDEO-DEMO: {$name}",
                'match_type' => $matchType,
                'pattern' => $pattern,
                'transaction_type' => $type,
                'source' => null,
                'priority' => $priority,
                'confidence' => 'high',
                'confidence_score' => 94,
                'is_active' => true,
            ]);
        }
    }

    /** @param array<string, Category> $categories */
    private function createMappings(User $user, array $categories, CarbonImmutable $referenceDate): void
    {
        foreach (['bank_csv', 'ewallet_csv'] as $source) {
            TransactionCategoryMapping::create([
                'user_id' => $user->id,
                'category_id' => $categories['Food and Beverage']->id,
                'transaction_type' => 'expense',
                'source' => $source,
                'normalized_merchant' => 'kopi langit selatan',
                'description_signature' => '',
                'confidence' => 'high',
                'confidence_score' => 95,
                'usage_count' => 2,
                'last_used_at' => $referenceDate->subMonthNoOverflow()->setTime(10, 0),
            ]);
        }
    }

    /**
     * @param  array<string, Wallet>  $wallets
     * @param  array<string, Category>  $categories
     * @return list<Transaction>
     */
    private function createTransactions(
        User $user,
        array $wallets,
        array $categories,
        CarbonImmutable $referenceDate,
        TransactionSanitizationService $sanitizer,
        TransactionTextNormalizationService $normalizer,
        TransactionImportDedupeService $dedupeService,
        TransactionBalanceService $balanceService,
    ): array {
        $currentStart = $referenceDate->startOfMonth();
        $previousStart = $currentStart->subMonthNoOverflow()->startOfMonth();
        $transactions = [];

        foreach ($this->transactionDefinitions() as $index => $definition) {
            $periodStart = $definition['period'] === 'current' ? $currentStart : $previousStart;
            $happenedAt = $periodStart
                ->addDays($definition['day'] - 1)
                ->setTimeFromTimeString($definition['time']);
            $wallet = $wallets[$definition['wallet']];
            $category = $categories[$definition['category']];
            $externalId = sprintf('VIDEO-DEMO-MANUAL-%s-%04d', strtoupper($definition['period']), $index + 1);
            $description = $sanitizer->sanitizeText($definition['description'], $user);
            $merchant = $sanitizer->sanitizeMerchant($definition['merchant'], $user);
            $normalizedMerchant = $normalizer->canonicalMerchant($merchant, $description);
            $normalizedDescription = $normalizer->descriptionSignature($description, $merchant) ?? 'video demo transaction';
            $fingerprint = $dedupeService->fingerprint(
                user: $user,
                wallet: $wallet,
                sourceType: $definition['source'],
                type: $definition['type'],
                amount: (float) $definition['amount'],
                fee: 0,
                currency: 'IDR',
                happenedAt: $happenedAt,
                normalizedMerchant: $normalizedMerchant,
                normalizedDescription: $normalizedDescription,
                referenceCode: $externalId,
                externalTransactionId: $externalId,
            );

            $transaction = Transaction::create([
                'user_id' => $user->id,
                'wallet_id' => $wallet->id,
                'category_id' => $category->id,
                'type' => $definition['type'],
                'amount' => $definition['amount'],
                'fee' => 0,
                'currency' => 'IDR',
                'merchant' => $merchant,
                'normalized_merchant' => $normalizedMerchant,
                'description' => $description,
                'note' => $description,
                'reference_code' => $externalId,
                'happened_at' => $happenedAt,
                'external_transaction_id' => $externalId,
                'dedupe_fingerprint' => $fingerprint,
                'source' => $definition['source'],
                'raw_payload' => [
                    'demo_dataset' => self::DEMO_DATASET,
                    'demo_run_id' => self::DEMO_RUN_ID,
                    'synthetic' => true,
                    'period' => $definition['period'],
                ],
                'sanitized_description' => $description,
                'normalized_description' => $normalizedDescription,
                'categorization_status' => 'categorized',
                'category_source' => 'user',
                'categorization_confidence' => 'high',
                'categorization_confidence_score' => 100,
                'categorization_explanation' => 'Synthetic video demo transaction with a verified category.',
                'categorized_at' => $happenedAt,
                'imported_at' => in_array($definition['source'], ['bank_csv', 'ewallet_csv'], true) ? $happenedAt : null,
            ]);

            TransactionCategoryLabel::create([
                'user_id' => $user->id,
                'transaction_id' => $transaction->id,
                'category_id' => $category->id,
                'sanitized_description' => $description,
                'transaction_type' => $definition['type'],
                'amount' => $definition['amount'],
                'source' => $definition['source'],
                'labeled_by' => 'video_demo_seed',
                'is_verified' => true,
            ]);

            $balanceService->apply($transaction);
            $transactions[] = $transaction;
        }

        return $transactions;
    }

    /** @return list<array<string, int|string>> */
    private function transactionDefinitions(): array
    {
        return [
            ['period' => 'current', 'day' => 1, 'time' => '08:30:00', 'wallet' => 'BCA Statement Import', 'category' => 'Salary', 'type' => 'income', 'amount' => 5500000, 'merchant' => 'Nusantara Digital Studio', 'description' => 'Monthly salary July', 'source' => 'manual'],
            ['period' => 'current', 'day' => 8, 'time' => '09:15:00', 'wallet' => 'BCA Statement Import', 'category' => 'Freelance Income', 'type' => 'income', 'amount' => 1200000, 'merchant' => 'Aurora Creative Client', 'description' => 'Freelance design payment', 'source' => 'manual'],
            ['period' => 'current', 'day' => 12, 'time' => '14:00:00', 'wallet' => 'Cash Wallet', 'category' => 'Sales', 'type' => 'income', 'amount' => 800000, 'merchant' => 'Weekend Pop-up Store', 'description' => 'Cash sales revenue', 'source' => 'manual'],
            ['period' => 'current', 'day' => 2, 'time' => '12:00:00', 'wallet' => 'Cash Wallet', 'category' => 'Food and Beverage', 'type' => 'expense', 'amount' => 180000, 'merchant' => 'Nusantara Bento Lab', 'description' => 'Team lunch package', 'source' => 'manual'],
            ['period' => 'current', 'day' => 2, 'time' => '12:10:00', 'wallet' => 'Cash Wallet', 'category' => 'Food and Beverage', 'type' => 'expense', 'amount' => 180000, 'merchant' => 'Nusantara Bento Lab', 'description' => 'Team lunch package', 'source' => 'manual'],
            ['period' => 'current', 'day' => 5, 'time' => '18:20:00', 'wallet' => 'Cash Wallet', 'category' => 'Food and Beverage', 'type' => 'expense', 'amount' => 195000, 'merchant' => 'Kopi Langit Selatan', 'description' => 'Client meeting refreshments', 'source' => 'manual'],
            ['period' => 'current', 'day' => 11, 'time' => '13:05:00', 'wallet' => 'Cash Wallet', 'category' => 'Food and Beverage', 'type' => 'expense', 'amount' => 195000, 'merchant' => 'Nusantara Bento Lab', 'description' => 'Project lunch package', 'source' => 'manual'],
            ['period' => 'current', 'day' => 3, 'time' => '07:45:00', 'wallet' => 'GoPay Statement Import', 'category' => 'Transportation', 'type' => 'expense', 'amount' => 60000, 'merchant' => 'MetroSwift Ride', 'description' => 'Ride to campus', 'source' => 'manual'],
            ['period' => 'current', 'day' => 10, 'time' => '20:15:00', 'wallet' => 'GoPay Statement Import', 'category' => 'Transportation', 'type' => 'expense', 'amount' => 60000, 'merchant' => 'MetroSwift Ride', 'description' => 'Ride from client meeting', 'source' => 'manual'],
            ['period' => 'current', 'day' => 6, 'time' => '10:30:00', 'wallet' => 'BCA Statement Import', 'category' => 'Shopping', 'type' => 'expense', 'amount' => 720000, 'merchant' => 'PixelNest Supplies', 'description' => 'Office peripherals purchase', 'source' => 'manual'],
            ['period' => 'current', 'day' => 4, 'time' => '06:00:00', 'wallet' => 'BCA Statement Import', 'category' => 'Bills', 'type' => 'expense', 'amount' => 285000, 'merchant' => 'Sagara Internet', 'description' => 'Monthly internet bill', 'source' => 'manual'],
            ['period' => 'current', 'day' => 13, 'time' => '21:00:00', 'wallet' => 'GoPay Statement Import', 'category' => 'Entertainment', 'type' => 'expense', 'amount' => 85000, 'merchant' => 'OrbitPlay Studio', 'description' => 'Weekend entertainment', 'source' => 'manual'],
            ['period' => 'current', 'day' => 7, 'time' => '11:00:00', 'wallet' => 'BCA Statement Import', 'category' => 'Business Operations', 'type' => 'expense', 'amount' => 300000, 'merchant' => 'Aurora Workspace', 'description' => 'Meeting room rental', 'source' => 'manual'],
            ['period' => 'current', 'day' => 9, 'time' => '16:30:00', 'wallet' => 'BCA Statement Import', 'category' => 'Education', 'type' => 'expense', 'amount' => 200000, 'merchant' => 'Akademi Digital Merah Putih', 'description' => 'Online finance workshop', 'source' => 'manual'],

            ['period' => 'previous', 'day' => 1, 'time' => '08:30:00', 'wallet' => 'BCA Statement Import', 'category' => 'Salary', 'type' => 'income', 'amount' => 5200000, 'merchant' => 'Nusantara Digital Studio', 'description' => 'Monthly salary June', 'source' => 'manual'],
            ['period' => 'previous', 'day' => 8, 'time' => '09:15:00', 'wallet' => 'BCA Statement Import', 'category' => 'Freelance Income', 'type' => 'income', 'amount' => 1000000, 'merchant' => 'Aurora Creative Client', 'description' => 'Freelance design payment', 'source' => 'manual'],
            ['period' => 'previous', 'day' => 15, 'time' => '14:00:00', 'wallet' => 'Cash Wallet', 'category' => 'Sales', 'type' => 'income', 'amount' => 700000, 'merchant' => 'Weekend Pop-up Store', 'description' => 'Cash sales revenue', 'source' => 'manual'],
            ['period' => 'previous', 'day' => 3, 'time' => '09:10:00', 'wallet' => 'BCA Statement Import', 'category' => 'Food and Beverage', 'type' => 'expense', 'amount' => 160000, 'merchant' => 'Kopi Langit Selatan', 'description' => 'QRIS KOPI LANGIT SELATAN JKT 3001', 'source' => 'bank_csv'],
            ['period' => 'previous', 'day' => 7, 'time' => '18:20:00', 'wallet' => 'GoPay Statement Import', 'category' => 'Food and Beverage', 'type' => 'expense', 'amount' => 160000, 'merchant' => 'Kopi Langit Selatan', 'description' => 'TRX KOPI LANGIT SELATAN', 'source' => 'ewallet_csv'],
            ['period' => 'previous', 'day' => 14, 'time' => '12:30:00', 'wallet' => 'Cash Wallet', 'category' => 'Food and Beverage', 'type' => 'expense', 'amount' => 165000, 'merchant' => 'Nusantara Bento Lab', 'description' => 'Lunch package', 'source' => 'manual'],
            ['period' => 'previous', 'day' => 21, 'time' => '12:30:00', 'wallet' => 'Cash Wallet', 'category' => 'Food and Beverage', 'type' => 'expense', 'amount' => 165000, 'merchant' => 'Nusantara Bento Lab', 'description' => 'Lunch package', 'source' => 'manual'],
            ['period' => 'previous', 'day' => 5, 'time' => '07:45:00', 'wallet' => 'GoPay Statement Import', 'category' => 'Transportation', 'type' => 'expense', 'amount' => 100000, 'merchant' => 'MetroSwift Ride', 'description' => 'Local transport', 'source' => 'manual'],
            ['period' => 'previous', 'day' => 19, 'time' => '20:15:00', 'wallet' => 'GoPay Statement Import', 'category' => 'Transportation', 'type' => 'expense', 'amount' => 100000, 'merchant' => 'MetroSwift Ride', 'description' => 'Local transport', 'source' => 'manual'],
            ['period' => 'previous', 'day' => 2, 'time' => '10:00:00', 'wallet' => 'BCA Statement Import', 'category' => 'Shopping', 'type' => 'expense', 'amount' => 120000, 'merchant' => 'PixelNest Supplies', 'description' => 'Small office supply', 'source' => 'manual'],
            ['period' => 'previous', 'day' => 6, 'time' => '10:00:00', 'wallet' => 'BCA Statement Import', 'category' => 'Shopping', 'type' => 'expense', 'amount' => 150000, 'merchant' => 'PixelNest Supplies', 'description' => 'Small office supply', 'source' => 'manual'],
            ['period' => 'previous', 'day' => 11, 'time' => '10:00:00', 'wallet' => 'BCA Statement Import', 'category' => 'Shopping', 'type' => 'expense', 'amount' => 170000, 'merchant' => 'PixelNest Supplies', 'description' => 'Small office supply', 'source' => 'manual'],
            ['period' => 'previous', 'day' => 18, 'time' => '10:00:00', 'wallet' => 'BCA Statement Import', 'category' => 'Shopping', 'type' => 'expense', 'amount' => 180000, 'merchant' => 'PixelNest Supplies', 'description' => 'Small office supply', 'source' => 'manual'],
            ['period' => 'previous', 'day' => 25, 'time' => '10:00:00', 'wallet' => 'BCA Statement Import', 'category' => 'Shopping', 'type' => 'expense', 'amount' => 180000, 'merchant' => 'PixelNest Supplies', 'description' => 'Small office supply', 'source' => 'manual'],
            ['period' => 'previous', 'day' => 4, 'time' => '06:00:00', 'wallet' => 'BCA Statement Import', 'category' => 'Bills', 'type' => 'expense', 'amount' => 285000, 'merchant' => 'Sagara Internet', 'description' => 'Monthly internet bill', 'source' => 'manual'],
            ['period' => 'previous', 'day' => 13, 'time' => '21:00:00', 'wallet' => 'GoPay Statement Import', 'category' => 'Entertainment', 'type' => 'expense', 'amount' => 250000, 'merchant' => 'OrbitPlay Studio', 'description' => 'Monthly entertainment', 'source' => 'manual'],
            ['period' => 'previous', 'day' => 9, 'time' => '11:00:00', 'wallet' => 'BCA Statement Import', 'category' => 'Business Operations', 'type' => 'expense', 'amount' => 500000, 'merchant' => 'Aurora Workspace', 'description' => 'Workspace operations', 'source' => 'manual'],
            ['period' => 'previous', 'day' => 16, 'time' => '16:30:00', 'wallet' => 'BCA Statement Import', 'category' => 'Education', 'type' => 'expense', 'amount' => 150000, 'merchant' => 'Akademi Digital Merah Putih', 'description' => 'Professional workshop', 'source' => 'manual'],
        ];
    }

    /** @param array<string, Category> $categories */
    private function createBudgets(User $user, array $categories, CarbonImmutable $referenceDate): void
    {
        $budgets = [
            'Food and Beverage' => 1150000,
            'Transportation' => 700000,
            'Shopping' => 2800000,
            'Entertainment' => 700000,
        ];

        foreach ($budgets as $categoryName => $amount) {
            Budget::create([
                'user_id' => $user->id,
                'category_id' => $categories[$categoryName]->id,
                'month' => $referenceDate->format('Y-m'),
                'amount' => $amount,
            ]);
        }
    }

    private function createOptionalStellarState(User $user, CarbonImmutable $referenceDate): bool
    {
        $publicKey = strtoupper(trim((string) env('LOKAFI_DEMO_STELLAR_PUBLIC_KEY')));

        if ($publicKey === '') {
            return false;
        }

        if (! preg_match('/^G[A-Z2-7]{55}$/', $publicKey)) {
            $this->warn('LOKAFI_DEMO_STELLAR_PUBLIC_KEY is invalid; Stellar demo state was skipped.');

            return false;
        }

        StellarWallet::create([
            'user_id' => $user->id,
            'public_key' => $publicKey,
            'network' => 'testnet',
            'wallet_provider' => 'freighter',
            'connected_at' => $referenceDate->setTime(8, 0),
        ]);

        Invoice::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'customer_name' => 'LokaFi Video Customer',
            'customer_email' => 'customer.video@example.test',
            'description' => 'Hackathon demo payment request',
            'fiat_currency' => 'IDR',
            'fiat_amount' => 250000,
            'demo_exchange_rate' => 2000,
            'stellar_asset_code' => 'XLM',
            'stellar_amount' => 125,
            'recipient_public_key' => $publicKey,
            'payment_memo' => 'VIDEO-DEMO-INVOICE-0001',
            'status' => Invoice::STATUS_PENDING,
            'expires_at' => $referenceDate->addDays(7)->endOfDay(),
            'paid_at' => null,
        ]);

        return true;
    }

    private function formatIdr(float|int|string $amount): string
    {
        return 'Rp '.number_format((float) $amount, 0, ',', '.');
    }
}
