<?php

namespace App\Services\Ai;

class FinancialInsightResponseValidator
{
    private const REQUIRED_KEYS = ['headline', 'summary', 'highlights', 'recommended_actions', 'disclaimer'];
    private const ALLOWED_KEYS = ['headline', 'summary', 'highlights', 'recommended_actions', 'disclaimer'];
    private const HIGHLIGHT_TYPES = ['positive', 'warning', 'critical', 'neutral'];
    private const ACTION_PRIORITIES = ['high', 'medium', 'low'];
    private const MAX_HIGHLIGHTS = 5;
    private const MAX_ACTIONS = 5;
    private const MAX_EVIDENCE_KEYS = 5;

    public function validate(string $rawResponse, array $allowedEvidenceKeys): array
    {
        try {
            $decoded = json_decode($this->extractJson($rawResponse), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new FinancialInsightValidationException('invalid_json');
        }

        if (!is_array($decoded) || array_values($decoded) === $decoded) {
            throw new FinancialInsightValidationException('invalid_schema');
        }

        foreach (self::REQUIRED_KEYS as $key) {
            if (!array_key_exists($key, $decoded)) {
                throw new FinancialInsightValidationException('missing_fields');
            }
        }

        foreach (array_keys($decoded) as $key) {
            if (!in_array($key, self::ALLOWED_KEYS, true)) {
                throw new FinancialInsightValidationException('unexpected_fields');
            }
        }

        $headline = $this->cleanText($decoded['headline'], 120, 'headline');
        $summary = $this->cleanText($decoded['summary'], 500, 'summary');
        $disclaimer = $this->cleanText($decoded['disclaimer'], 240, 'disclaimer');

        if (!is_array($decoded['highlights']) || count($decoded['highlights']) > self::MAX_HIGHLIGHTS) {
            throw new FinancialInsightValidationException('invalid_highlights');
        }

        if (!is_array($decoded['recommended_actions']) || count($decoded['recommended_actions']) > self::MAX_ACTIONS) {
            throw new FinancialInsightValidationException('invalid_actions');
        }

        $highlights = array_map(
            fn (mixed $highlight) => $this->validateHighlight($highlight, $allowedEvidenceKeys),
            $decoded['highlights'],
        );
        $actions = array_map(
            fn (mixed $action) => $this->validateAction($action, $allowedEvidenceKeys),
            $decoded['recommended_actions'],
        );

        $this->rejectForbiddenAdvice($headline . ' ' . $summary . ' ' . $disclaimer . ' ' . json_encode([$highlights, $actions]));

        return [
            'headline' => $headline,
            'summary' => $summary,
            'highlights' => array_values($highlights),
            'recommended_actions' => array_values($actions),
            'disclaimer' => $disclaimer,
        ];
    }

    private function validateHighlight(mixed $highlight, array $allowedEvidenceKeys): array
    {
        if (!is_array($highlight)) {
            throw new FinancialInsightValidationException('invalid_highlight');
        }

        foreach (['type', 'title', 'description', 'evidence_keys'] as $key) {
            if (!array_key_exists($key, $highlight)) {
                throw new FinancialInsightValidationException('invalid_highlight');
            }
        }

        if (!in_array($highlight['type'], self::HIGHLIGHT_TYPES, true)) {
            throw new FinancialInsightValidationException('invalid_highlight_type');
        }

        if (!is_array($highlight['evidence_keys']) || count($highlight['evidence_keys']) > self::MAX_EVIDENCE_KEYS) {
            throw new FinancialInsightValidationException('invalid_evidence_keys');
        }

        $evidenceKeys = $this->validateEvidenceKeys($highlight['evidence_keys'], $allowedEvidenceKeys);

        return [
            'type' => $highlight['type'],
            'title' => $this->cleanText($highlight['title'], 100, 'highlight_title'),
            'description' => $this->cleanText($highlight['description'], 280, 'highlight_description'),
            'evidence_keys' => $evidenceKeys,
        ];
    }

    private function validateAction(mixed $action, array $allowedEvidenceKeys): array
    {
        if (!is_array($action)) {
            throw new FinancialInsightValidationException('invalid_action');
        }

        foreach (['priority', 'title', 'description', 'related_metric'] as $key) {
            if (!array_key_exists($key, $action)) {
                throw new FinancialInsightValidationException('invalid_action');
            }
        }

        if (!in_array($action['priority'], self::ACTION_PRIORITIES, true)) {
            throw new FinancialInsightValidationException('invalid_action_priority');
        }

        $relatedMetric = $action['related_metric'];

        if (is_string($relatedMetric) && !in_array($relatedMetric, $allowedEvidenceKeys, true)) {
            $relatedMetric = $this->normalizeRelatedMetricAlias($relatedMetric, $allowedEvidenceKeys);
        }

        if ($relatedMetric !== null && (!is_string($relatedMetric) || !in_array($relatedMetric, $allowedEvidenceKeys, true))) {
            throw new FinancialInsightValidationException('invalid_related_metric');
        }

        return [
            'priority' => $action['priority'],
            'title' => $this->cleanText($action['title'], 100, 'action_title'),
            'description' => $this->cleanText($action['description'], 280, 'action_description'),
            'related_metric' => $relatedMetric,
        ];
    }

    private function validateEvidenceKeys(array $keys, array $allowedEvidenceKeys): array
    {
        $validated = [];

        foreach ($keys as $key) {
            if (!is_string($key) || !in_array($key, $allowedEvidenceKeys, true)) {
                throw new FinancialInsightValidationException('unsupported_evidence_key');
            }

            $validated[] = $key;
        }

        return array_values(array_unique($validated));
    }

    private function normalizeRelatedMetricAlias(string $relatedMetric, array $allowedEvidenceKeys): string
    {
        $aliases = [
            'largest_expense_reduction_20pct' => 'action.largest_expense_reduction_20pct',
            'safe_savings_allocation' => 'action.safe_savings_allocation',
        ];

        $normalized = $aliases[$relatedMetric] ?? $relatedMetric;

        return in_array($normalized, $allowedEvidenceKeys, true) ? $normalized : $relatedMetric;
    }

    private function cleanText(mixed $value, int $maxLength, string $field): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new FinancialInsightValidationException("invalid_{$field}");
        }

        $text = trim($value);

        if (mb_strlen($text) > $maxLength) {
            throw new FinancialInsightValidationException("{$field}_too_long");
        }

        $this->rejectNumbers($text);
        $this->rejectSecrets($text);
        $this->rejectForbiddenAdvice($text);

        return $text;
    }

    private function rejectNumbers(string $text): void
    {
        $controlledTargetsRemoved = preg_replace('/\b(?:20|25)%/i', '', $text) ?? $text;

        if (preg_match('/(?:\b(?:rp|idr|usd|xlm)\b|\$|%|\b\d+(?:[.,]\d+)?\b)/i', $controlledTargetsRemoved)) {
            throw new FinancialInsightValidationException('hallucinated_number');
        }
    }

    private function rejectSecrets(string $text): void
    {
        if (preg_match('/(secret key|mnemonic|recovery phrase|password|otp|pin|token)/i', $text)) {
            throw new FinancialInsightValidationException('sensitive_text_detected');
        }
    }

    private function rejectForbiddenAdvice(string $text): void
    {
        if (preg_match('/\b(buy|sell|short|long|invest in|purchase|beli|jual|investasi|menanamkan)\b.*\b(stock|saham|crypto|kripto|bitcoin|ethereum|loan|pinjaman|kredit|mutual fund|reksa dana|obligasi|emas)\b/i', $text)) {
            throw new FinancialInsightValidationException('forbidden_financial_product_recommendation');
        }
    }

    private function extractJson(string $rawResponse): string
    {
        $text = trim($rawResponse);

        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/is', $text, $matches)) {
            return trim($matches[1]);
        }

        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start !== false && $end !== false && $end > $start) {
            return substr($text, $start, $end - $start + 1);
        }

        return $text;
    }
}
