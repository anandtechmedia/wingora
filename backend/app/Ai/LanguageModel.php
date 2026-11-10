<?php

namespace App\Ai;

/**
 * The only way features reach a language model (AGENTS.md section 18). Providers are chosen by environment
 * variables; output is advisory text and is never applied to records without a person's confirmation.
 */
interface LanguageModel
{
    public function available(): bool;

    public function name(): string;

    /** @throws AiUnavailable */
    public function complete(string $system, string $prompt, int $maxTokens = 600): Completion;
}
