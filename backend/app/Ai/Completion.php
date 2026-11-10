<?php

namespace App\Ai;

final class Completion
{
    public function __construct(public readonly string $text, public readonly ?int $inputTokens = null, public readonly ?int $outputTokens = null) {}
}
