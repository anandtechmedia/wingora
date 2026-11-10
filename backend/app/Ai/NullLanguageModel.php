<?php

namespace App\Ai;

/** Default when no provider is configured: AI features report themselves unavailable and pages work without them. */
final class NullLanguageModel implements LanguageModel
{
    public function available(): bool
    {
        return false;
    }

    public function name(): string
    {
        return 'none';
    }

    public function complete(string $system, string $prompt, int $maxTokens = 600): Completion
    {
        throw new AiUnavailable('No AI provider is configured.');
    }
}
