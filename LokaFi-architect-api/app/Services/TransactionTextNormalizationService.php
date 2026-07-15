<?php

namespace App\Services;

use Illuminate\Support\Str;

class TransactionTextNormalizationService
{
    private const PREFIX_WORDS = [
        'qris',
        'trx',
        'transaksi',
        'pembayaran',
        'pembyr',
        'bayar',
        'payment',
        'pay',
        'debit',
        'kredit',
        'credit',
        'purchase',
        'pos',
        'merchant',
        'mpm',
    ];

    private const NOISE_WORDS = [
        'id',
        'indonesia',
        'jkt',
        'jakarta',
        'bdg',
        'bandung',
        'sby',
        'surabaya',
        'dki',
        'kota',
        'kab',
        'kabupaten',
        'ref',
        'reff',
        'no',
        'nomor',
    ];

    public function basicNormalize(?string $value): ?string
    {
        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        $text = Str::ascii($text);
        $text = mb_strtolower($text);
        $text = preg_replace('/\[[a-z_]+\]/', ' ', $text) ?? $text;
        $text = preg_replace('/[^a-z0-9]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return trim($text) ?: null;
    }

    public function canonicalMerchant(?string $merchant, ?string $description = null): ?string
    {
        $source = $merchant ?: $description;
        $normalized = $this->basicNormalize($source);

        if (!$normalized) {
            return null;
        }

        $tokens = $this->cleanTokens(explode(' ', $normalized), stripPrefixes: true);

        return $this->tokensToText($tokens);
    }

    public function descriptionSignature(?string $description, ?string $merchant = null): ?string
    {
        $normalized = $this->basicNormalize(trim(($merchant ?? '') . ' ' . ($description ?? '')));

        if (!$normalized) {
            return null;
        }

        $tokens = $this->cleanTokens(explode(' ', $normalized), stripPrefixes: true);
        $tokens = array_slice($tokens, 0, 8);

        return $this->tokensToText($tokens);
    }

    public function similarity(?string $left, ?string $right): float
    {
        $leftTokens = $this->tokenSet($left);
        $rightTokens = $this->tokenSet($right);

        if (count($leftTokens) === 0 || count($rightTokens) === 0) {
            return 0.0;
        }

        $intersection = count(array_intersect($leftTokens, $rightTokens));
        $union = count(array_unique(array_merge($leftTokens, $rightTokens)));

        return $union === 0 ? 0.0 : $intersection / $union;
    }

    public function containsKeyword(?string $haystack, string $pattern): bool
    {
        $normalizedHaystack = ' ' . ($this->basicNormalize($haystack) ?? '') . ' ';

        if (trim($normalizedHaystack) === '') {
            return false;
        }

        $keywords = collect(explode(',', $pattern))
            ->map(fn (string $keyword) => $this->basicNormalize($keyword))
            ->filter()
            ->values();

        foreach ($keywords as $keyword) {
            if (str_contains($normalizedHaystack, ' ' . $keyword . ' ')) {
                return true;
            }

            if (str_contains($keyword, ' ') && str_contains($normalizedHaystack, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function cleanTokens(array $tokens, bool $stripPrefixes): array
    {
        $tokens = array_values(array_filter(array_map('trim', $tokens), function (string $token) {
            if ($token === '') {
                return false;
            }

            if (preg_match('/^\d+$/', $token)) {
                return false;
            }

            if (mb_strlen($token) <= 1) {
                return false;
            }

            return !in_array($token, self::NOISE_WORDS, true);
        }));

        if ($stripPrefixes) {
            while (count($tokens) > 0 && in_array($tokens[0], self::PREFIX_WORDS, true)) {
                array_shift($tokens);
            }
        }

        $tokens = array_values(array_filter($tokens, fn (string $token) => !in_array($token, self::PREFIX_WORDS, true)));

        while (count($tokens) > 0 && in_array($tokens[count($tokens) - 1], self::NOISE_WORDS, true)) {
            array_pop($tokens);
        }

        return $tokens;
    }

    private function tokenSet(?string $value): array
    {
        $normalized = $this->basicNormalize($value);

        if (!$normalized) {
            return [];
        }

        return array_values(array_unique($this->cleanTokens(explode(' ', $normalized), stripPrefixes: false)));
    }

    private function tokensToText(array $tokens): ?string
    {
        $text = trim(implode(' ', $tokens));

        return $text === '' ? null : $text;
    }
}
