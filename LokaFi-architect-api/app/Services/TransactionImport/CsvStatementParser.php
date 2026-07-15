<?php

namespace App\Services\TransactionImport;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class CsvStatementParser
{
    public const MAX_ROWS = 500;

    public function parse(UploadedFile $file): array
    {
        $path = $file->getRealPath();

        if (!$path || !is_readable($path)) {
            throw ValidationException::withMessages([
                'file' => 'File CSV tidak bisa dibaca.',
            ]);
        }

        $delimiter = $this->detectDelimiter($path);
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw ValidationException::withMessages([
                'file' => 'File CSV tidak bisa dibuka.',
            ]);
        }

        $headers = fgetcsv($handle, 0, $delimiter);

        if (!$headers || count($headers) === 0) {
            fclose($handle);

            throw ValidationException::withMessages([
                'file' => 'CSV harus memiliki header kolom.',
            ]);
        }

        $columns = $this->normalizeHeaders($headers);

        if (count(array_filter($columns)) === 0) {
            fclose($handle);

            throw ValidationException::withMessages([
                'file' => 'Header CSV tidak valid.',
            ]);
        }

        $rows = [];
        $rowNumber = 1;

        while (($line = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rowNumber++;

            if ($this->isEmptyRow($line)) {
                continue;
            }

            if (count($rows) >= self::MAX_ROWS) {
                fclose($handle);

                throw ValidationException::withMessages([
                    'file' => 'CSV maksimal berisi ' . self::MAX_ROWS . ' baris transaksi.',
                ]);
            }

            $row = [];

            foreach ($columns as $index => $column) {
                $row[$column] = isset($line[$index]) ? trim((string) $line[$index]) : '';
            }

            $rows[] = [
                'row_number' => $rowNumber,
                'payload' => $row,
            ];
        }

        fclose($handle);

        if (count($rows) === 0) {
            throw ValidationException::withMessages([
                'file' => 'CSV tidak memiliki baris transaksi.',
            ]);
        }

        return [
            'file_hash' => hash_file('sha256', $path),
            'file_size_bytes' => (int) ($file->getSize() ?? 0),
            'columns' => $columns,
            'rows' => $rows,
        ];
    }

    private function detectDelimiter(string $path): string
    {
        $handle = fopen($path, 'rb');
        $line = $handle ? (string) fgets($handle) : '';

        if ($handle) {
            fclose($handle);
        }

        $delimiters = [',' => 0, ';' => 0, "\t" => 0];

        foreach ($delimiters as $delimiter => $count) {
            $delimiters[$delimiter] = substr_count($line, $delimiter);
        }

        arsort($delimiters);

        return (string) array_key_first($delimiters);
    }

    private function normalizeHeaders(array $headers): array
    {
        $columns = [];
        $seen = [];

        foreach ($headers as $index => $header) {
            $column = trim((string) $header);
            $column = preg_replace('/^\xEF\xBB\xBF/', '', $column) ?? $column;

            if ($column === '') {
                $column = 'column_' . ($index + 1);
            }

            $baseColumn = $column;
            $suffix = 2;

            while (isset($seen[mb_strtolower($column)])) {
                $column = "{$baseColumn}_{$suffix}";
                $suffix++;
            }

            $seen[mb_strtolower($column)] = true;
            $columns[] = $column;
        }

        return $columns;
    }

    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }
}
