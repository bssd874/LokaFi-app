<?php

namespace App\Services\Ai;

class FakeAiProviderClient implements AiProviderClientInterface
{
    public function categorize(array $payload): string
    {
        if (($payload['task'] ?? null) === 'financial_insight') {
            return $this->financialInsight($payload);
        }

        $text = strtolower(trim(implode(' ', [
            $payload['sanitized_description'] ?? '',
            $payload['normalized_description'] ?? '',
            $payload['normalized_merchant'] ?? '',
        ])));

        $category = $this->matchCategory($payload['allowed_categories'] ?? [], $text);

        return json_encode([
            'category_id' => $category['id'] ?? null,
            'confidence' => $category ? 0.78 : 0.25,
            'needs_review' => true,
            'reason' => $category
                ? 'Fake provider matched sanitized keywords to an allowed category.'
                : 'Fake provider did not find a safe category match.',
        ], JSON_THROW_ON_ERROR);
    }

    private function matchCategory(array $categories, string $text): ?array
    {
        $rules = [
            ['tokens' => ['makan', 'warung', 'kopi', 'roti', 'food'], 'names' => ['makanan', 'minuman', 'food']],
            ['tokens' => ['gojek', 'grab', 'ride', 'transport'], 'names' => ['transport']],
            ['tokens' => ['gaji', 'salary', 'payroll', 'honor', 'income'], 'names' => ['pemasukan', 'income']],
            ['tokens' => ['tagihan', 'bill', 'listrik', 'pulsa'], 'names' => ['tagihan']],
            ['tokens' => ['belanja', 'toko', 'shop'], 'names' => ['belanja']],
        ];

        foreach ($rules as $rule) {
            if (!$this->containsAny($text, $rule['tokens'])) {
                continue;
            }

            foreach ($categories as $category) {
                $name = strtolower((string) ($category['name'] ?? ''));

                if ($this->containsAny($name, $rule['names'])) {
                    return $category;
                }
            }
        }

        return null;
    }

    private function containsAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function financialInsight(array $payload): string
    {
        $evidenceKeys = $payload['allowed_evidence_keys'] ?? [];
        $summaryKey = $this->firstAvailable($evidenceKeys, [
            'summary.net_cashflow',
            'summary.savings_rate',
            'summary.total_expense',
        ]);
        $budgetKey = $this->firstAvailable($evidenceKeys, [
            'budget_alerts.alerts_count',
            'budget_alerts.total_remaining',
        ]);
        $anomalyKey = $this->firstAvailable($evidenceKeys, [
            'anomalies.count',
            'anomalies.top_severity',
        ]);

        return json_encode([
            'headline' => 'Ringkasan keuangan siap ditinjau',
            'summary' => 'Analisis ini merangkum kondisi keuangan dari metrik deterministik yang sudah dihitung aplikasi.',
            'highlights' => array_values(array_filter([
                [
                    'type' => 'neutral',
                    'title' => 'Arus kas perlu dipantau',
                    'description' => 'Gunakan metrik pendukung untuk melihat posisi arus kas periode ini.',
                    'evidence_keys' => array_values(array_filter([$summaryKey])),
                ],
                $budgetKey ? [
                    'type' => 'warning',
                    'title' => 'Budget perlu dicek',
                    'description' => 'Beberapa indikator budget dapat menjadi prioritas tinjauan.',
                    'evidence_keys' => [$budgetKey],
                ] : null,
                $anomalyKey ? [
                    'type' => 'warning',
                    'title' => 'Aktivitas tidak biasa terdeteksi',
                    'description' => 'Periksa unusual activity sebelum mengambil tindakan lanjutan.',
                    'evidence_keys' => [$anomalyKey],
                ] : null,
            ])),
            'recommended_actions' => [
                [
                    'priority' => 'high',
                    'title' => 'Tinjau kategori pengeluaran utama',
                    'description' => 'Mulai dari metrik pendukung dengan kontribusi terbesar terhadap pengeluaran.',
                    'related_metric' => $summaryKey,
                ],
                [
                    'priority' => 'medium',
                    'title' => 'Periksa budget yang mendekati batas',
                    'description' => 'Gunakan budget alert untuk menentukan kategori yang butuh penyesuaian.',
                    'related_metric' => $budgetKey,
                ],
            ],
            'disclaimer' => 'AI-generated insights are informational and based on recorded transaction data. They are not professional financial advice.',
        ], JSON_THROW_ON_ERROR);
    }

    private function firstAvailable(array $keys, array $preferred): ?string
    {
        foreach ($preferred as $key) {
            if (in_array($key, $keys, true)) {
                return $key;
            }
        }

        return $keys[0] ?? null;
    }
}
