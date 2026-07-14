<?php

namespace App\Console\Commands;

use App\Services\TransactionDatasetExportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ExportTransactionDataset extends Command
{
    protected $signature = 'dataset:export-transactions
        {--user= : Filter dataset by user id}
        {--verified-only : Export only verified labels}
        {--output=storage/app/datasets/transactions_labeled.csv : CSV output path}
        {--force : Overwrite output file without confirmation}';

    protected $description = 'Export sanitized labeled transaction dataset to CSV.';

    public function handle(TransactionDatasetExportService $exportService): int
    {
        $outputOption = (string) $this->option('output');
        $output = preg_match('/^(?:[A-Za-z]:[\/\\\\]|\/)/', $outputOption)
            ? $outputOption
            : base_path($outputOption);
        $userId = $this->option('user') ? (int) $this->option('user') : null;
        $verifiedOnly = (bool) $this->option('verified-only');

        if (File::exists($output) && !$this->option('force')) {
            if (!$this->confirm("File {$output} sudah ada. Overwrite?")) {
                $this->warn('Export dibatalkan.');

                return self::FAILURE;
            }
        }

        $result = $exportService->writeCsv(
            path: $output,
            userId: $userId,
            verifiedOnly: $verifiedOnly,
        );

        $this->info("Dataset berhasil diekspor: {$result['path']}");
        $this->line("Data diekspor: {$result['exported_count']}");
        $this->line("Data dilewati karena belum terverifikasi: {$result['skipped_unverified_count']}");

        return self::SUCCESS;
    }
}
