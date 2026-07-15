<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;

class TransactionSanitizationService
{
    public function sanitizeText(?string $value, ?User $user = null): string
    {
        $text = trim((string) $value);

        if ($text === '') {
            return '';
        }

        $text = Str::ascii($text);
        $text = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $text) ?? $text;
        $text = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[email]', $text) ?? $text;
        $text = preg_replace('/\b(?:\+?62|0)8[0-9\-\s]{7,14}\b/i', '[phone]', $text) ?? $text;
        $text = preg_replace('/\b(?:\d[ -]?){13,19}\b/', '[card]', $text) ?? $text;
        $text = preg_replace('/\b(?:rek|rekening|acct|account|no\.?\s*rek)\s*[:#-]?\s*\d{5,20}\b/i', '$1 [account]', $text) ?? $text;
        $text = preg_replace('/\b\d{8,20}\b/', '[number]', $text) ?? $text;
        $text = preg_replace('/\b(otp|kode\s*otp)\s*[:#-]?\s*[A-Z0-9]{4,10}\b/i', '$1 [otp]', $text) ?? $text;
        $text = preg_replace('/\b(token|credential|username|login|password|passwd|pin|secret)\s*[:#=-]?\s*\S+/i', '$1 [redacted]', $text) ?? $text;

        if ($user && $user->name) {
            $nameParts = collect(preg_split('/\s+/', trim($user->name)) ?: [])
                ->filter(fn (string $part) => mb_strlen($part) >= 3)
                ->values();

            if ($nameParts->count() >= 2) {
                $pattern = '/\b' . preg_quote($nameParts->implode(' '), '/') . '\b/i';
                $text = preg_replace($pattern, '[name]', $text) ?? $text;
            }
        }

        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return trim($text);
    }

    public function sanitizeMerchant(?string $value, ?User $user = null): ?string
    {
        $sanitized = $this->sanitizeText($value, $user);

        return $sanitized === '' ? null : mb_substr($sanitized, 0, 150);
    }

    public function sanitizePayload(mixed $payload, ?User $user = null): mixed
    {
        if (is_array($payload)) {
            $sanitized = [];

            foreach ($payload as $key => $value) {
                $keyString = (string) $key;

                if (preg_match('/(username|user_name|login|password|passwd|pin|otp|token|secret|credential|access_token|refresh_token)/i', $keyString)) {
                    $sanitized[$key] = '[redacted]';
                    continue;
                }

                $sanitized[$key] = $this->sanitizePayload($value, $user);
            }

            return $sanitized;
        }

        if (is_string($payload)) {
            return $this->sanitizeText($payload, $user);
        }

        return $payload;
    }

    public function transactionDescription(array $transaction): string
    {
        return (string) (
            $transaction['description']
            ?? $transaction['note']
            ?? $transaction['merchant']
            ?? $transaction['descriptor']
            ?? ''
        );
    }
}
