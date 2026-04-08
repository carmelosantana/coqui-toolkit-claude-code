<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\ClaudeCode\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Toolkits\ClaudeCode\ClaudeCodeClient;

/**
 * Check Claude Code CLI installation, authentication status, and version.
 */
final readonly class ClaudeCodeStatusTool
{
    private const array ACTIONS = ['check', 'auth_status', 'version'];

    public function __construct(
        private ClaudeCodeClient $client,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'claude_code_status',
            description: 'Check Claude Code CLI installation and authentication status. '
                . 'Use "check" for a full health report, "auth_status" for authentication details, '
                . 'or "version" to get the installed version.',
            parameters: [
                new EnumParameter('action', 'Operation to perform', self::ACTIONS),
            ],
            callback: fn(array $args): ToolResult => $this->execute($args),
        );
    }

    /**
     * @param array<string, mixed> $args
     */
    private function execute(array $args): ToolResult
    {
        $action = (string) ($args['action'] ?? '');

        return match ($action) {
            'check'       => $this->check(),
            'auth_status' => $this->authStatus(),
            'version'     => $this->version(),
            default       => ToolResult::error(
                "Unknown action: {$action}. Valid actions: " . implode(', ', self::ACTIONS),
            ),
        };
    }

    private function check(): ToolResult
    {
        $status = [
            'available' => $this->client->isAvailable(),
            'binary_path' => $this->client->resolveBinary() ?: null,
        ];

        if ($status['available']) {
            // Get version
            try {
                $versionResult = $this->client->runRaw('--version');
                $status['version'] = trim($versionResult['stdout']);
            } catch (\Throwable) {
                $status['version'] = 'unknown';
            }

            // Get auth status
            try {
                $authResult = $this->client->runRaw('auth', 'status');
                $status['authenticated'] = $authResult['exit_code'] === 0;
                $authOutput = trim($authResult['stdout'] . ' ' . $authResult['stderr']);
                $status['auth_details'] = $authOutput !== '' ? $authOutput : null;
            } catch (\Throwable) {
                $status['authenticated'] = false;
            }
        }

        return ToolResult::success(
            json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }

    private function authStatus(): ToolResult
    {
        if (!$this->client->isAvailable()) {
            return ToolResult::error(
                'Claude Code CLI (claude) is not available. '
                . 'Install it with: npm install -g @anthropic-ai/claude-code',
            );
        }

        try {
            $result = $this->client->runRaw('auth', 'status');

            $output = [
                'authenticated' => $result['exit_code'] === 0,
                'details' => trim($result['stdout'] . "\n" . $result['stderr']),
            ];

            return ToolResult::success(
                json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            );
        } catch (\Throwable $e) {
            return ToolResult::error('Failed to check auth status: ' . $e->getMessage());
        }
    }

    private function version(): ToolResult
    {
        if (!$this->client->isAvailable()) {
            return ToolResult::error(
                'Claude Code CLI (claude) is not available. '
                . 'Install it with: npm install -g @anthropic-ai/claude-code',
            );
        }

        try {
            $result = $this->client->runRaw('--version');

            return ToolResult::success(trim($result['stdout']));
        } catch (\Throwable $e) {
            return ToolResult::error('Failed to get version: ' . $e->getMessage());
        }
    }
}
