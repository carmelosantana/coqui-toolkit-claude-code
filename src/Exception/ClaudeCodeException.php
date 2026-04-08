<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\ClaudeCode\Exception;

/**
 * Thrown when the Claude Code CLI process fails or returns unexpected output.
 */
final class ClaudeCodeException extends \RuntimeException
{
    public static function binaryNotFound(string $binary): self
    {
        return new self(
            sprintf(
                'Claude Code CLI not found at "%s". Install it: npm install -g @anthropic-ai/claude-code',
                $binary,
            ),
        );
    }

    public static function processFailure(string $reason): self
    {
        return new self('Claude Code process failed: ' . $reason);
    }

    public static function invalidJson(string $output): self
    {
        return new self(
            'Claude Code returned non-JSON output: ' . mb_substr($output, 0, 300),
        );
    }

    public static function timeout(int $seconds): self
    {
        return new self(sprintf('Claude Code timed out after %d seconds.', $seconds));
    }

    public static function authRequired(): self
    {
        return new self('Claude Code is not authenticated. Run: claude auth login');
    }
}
