<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OperationalIntentClassifier
{
    private const ALLOWED_INTENTS = [
        'cash_status',
        'sales_today',
        'report_summary',
        'inventory_alerts',
        'client_lookup',
        'users_summary',
        'catalog',
        'other',
    ];

    public function classify(string $message): ?string
    {
        $apiKey = config('services.gemini.key');

        if (blank($apiKey)) {
            return null;
        }

        try {
            $response = Http::acceptJson()
                ->timeout(4)
                ->post('https://generativelanguage.googleapis.com/v1beta/models/' . config('services.gemini.model') . ':generateContent?key=' . $apiKey, [
                    'contents' => [[
                        'role' => 'user',
                        'parts' => [[
                            'text' => $this->instructions() . "\n\nConsulta: " . $this->sanitize($message),
                        ]],
                    ]],
                    'generationConfig' => [
                        'temperature' => 0,
                        'maxOutputTokens' => 32,
                        'responseMimeType' => 'application/json',
                    ],
                ]);

            if (!$response->successful()) {
                Log::warning('Operational assistant intent classification failed.', ['status' => $response->status()]);

                return null;
            }

            $text = data_get($response->json(), 'candidates.0.content.parts.0.text');
            $intent = json_decode((string) $text, true)['intent'] ?? null;

            return in_array($intent, self::ALLOWED_INTENTS, true) ? $intent : null;
        } catch (\Throwable $exception) {
            Log::warning('Operational assistant intent classification unavailable.', ['exception' => $exception->getMessage()]);

            return null;
        }
    }

    private function instructions(): string
    {
        return <<<'PROMPT'
You are an intent classifier for Botica L y L's internal pharmacy operations system.
Return only JSON: {"intent":"one_allowed_value"}.
Allowed values:
- cash_status: cash register status, cash, expected amount, opening.
- sales_today: sales, amounts sold, income today.
- report_summary: operational summaries, billing, reports.
- inventory_alerts: low stock, batches, expiry, expired stock.
- client_lookup: a customer lookup by document.
- users_summary: system users or employees.
- catalog: product, price, active ingredient, presentation, availability.
- other: anything unrelated to the Botica L y L system.
Never provide clinical or medical advice, execute changes, or infer an intent outside this list.
PROMPT;
    }

    private function sanitize(string $message): string
    {
        $message = preg_replace('/\b\d{8,11}\b/', '[DOCUMENTO]', $message);
        $message = preg_replace('/[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}/', '[CORREO]', $message);

        return mb_substr($message, 0, 500);
    }
}
