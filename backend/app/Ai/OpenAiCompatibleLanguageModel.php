<?php

namespace App\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Chat-completions over HTTPS for any OpenAI-compatible endpoint. No SDK dependency. */
final class OpenAiCompatibleLanguageModel implements LanguageModel
{
    public function __construct(private readonly array $config) {}

    public function available(): bool
    {
        return ! empty($this->config['base_url']) && ! empty($this->config['api_key']) && ! empty($this->config['model'])
            && str_starts_with((string) $this->config['base_url'], 'https://');
    }

    public function name(): string
    {
        return (string) ($this->config['model'] ?? 'unknown');
    }

    public function complete(string $system, string $prompt, int $maxTokens = 600): Completion
    {
        if (! $this->available()) {
            throw new AiUnavailable('The AI provider is not fully configured.');
        }
        try {
            $response = Http::withToken($this->config['api_key'])->timeout($this->config['timeout'] ?? 20)->acceptJson()
                ->post(rtrim($this->config['base_url'], '/').'/chat/completions', [
                    'model' => $this->config['model'],
                    'max_tokens' => $maxTokens,
                    'temperature' => 0.2,
                    'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $prompt]],
                ]);
        } catch (ConnectionException) {
            throw new AiUnavailable('The AI provider could not be reached.');
        }
        if (! $response->successful()) {
            throw new AiUnavailable('The AI provider returned an error.');
        }

        return new Completion((string) $response->json('choices.0.message.content', ''), $response->json('usage.prompt_tokens'), $response->json('usage.completion_tokens'));
    }
}
