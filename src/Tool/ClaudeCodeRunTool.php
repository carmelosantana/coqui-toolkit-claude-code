<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\ClaudeCode\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\BoolParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Toolkits\ClaudeCode\ClaudeCodeClient;

/**
 * Execute a prompt against Claude Code in a target project directory.
 *
 * Returns the Claude Code result and session_id for multi-turn follow-ups.
 */
final readonly class ClaudeCodeRunTool
{
    public function __construct(
        private ClaudeCodeClient $client,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'claude_code_run',
            description: 'Send a prompt to the Claude Code CLI targeting a project directory. '
                . 'Returns the result and a session_id for follow-up turns. '
                . 'Claude Code can read, write, edit files, run shell commands, and more.',
            parameters: [
                new StringParameter('prompt', 'The task or question for Claude Code to handle'),
                new StringParameter('directory', 'Absolute path to the project directory to work in'),
                new EnumParameter(
                    'model',
                    'Model to use: sonnet (fast, balanced), opus (most capable), haiku (fast, cheap)',
                    ['sonnet', 'opus', 'haiku'],
                    required: false,
                ),
                new NumberParameter(
                    'max_turns',
                    'Maximum number of agent turns (tool use cycles). Default: unlimited.',
                    required: false,
                    integer: true,
                    minimum: 1,
                ),
                new NumberParameter(
                    'max_budget_usd',
                    'Maximum cost in USD before Claude Code stops (e.g. 0.50 for 50 cents).',
                    required: false,
                    minimum: 0.01,
                ),
                new StringParameter(
                    'allowed_tools',
                    'Comma-separated tool names to pre-approve without confirmation '
                        . '(e.g. "Bash,Read,Edit,Write"). Skips interactive permission prompts.',
                    required: false,
                ),
                new EnumParameter(
                    'permission_mode',
                    'Permission mode: "default" (interactive approvals), "plan" (read-only planning, no file writes).',
                    ['default', 'plan'],
                    required: false,
                ),
                new StringParameter(
                    'append_system_prompt',
                    'Additional instructions appended to the system prompt for this run.',
                    required: false,
                ),
                new StringParameter(
                    'session_id',
                    'Session ID to resume a specific previous conversation.',
                    required: false,
                ),
                new BoolParameter(
                    'continue_session',
                    'If true, continues the most recent session for the target directory.',
                    required: false,
                ),
                new BoolParameter(
                    'dangerously_skip_permissions',
                    'If true, skips ALL permission prompts. Only use when you fully trust the prompt.',
                    required: false,
                ),
                new NumberParameter(
                    'timeout',
                    'Process timeout in seconds. Default: 300 (5 minutes). Increase for long-running tasks.',
                    required: false,
                    integer: true,
                    minimum: 10,
                ),
            ],
            callback: fn(array $args): ToolResult => $this->execute($args),
        );
    }

    /**
     * @param array<string, mixed> $args
     */
    private function execute(array $args): ToolResult
    {
        $prompt = trim((string) ($args['prompt'] ?? ''));
        if ($prompt === '') {
            return ToolResult::error('prompt is required.');
        }

        $directory = trim((string) ($args['directory'] ?? ''));
        if ($directory === '') {
            return ToolResult::error('directory is required.');
        }

        if (!is_dir($directory)) {
            return ToolResult::error("Directory does not exist: {$directory}");
        }

        if (!$this->client->isAvailable()) {
            return ToolResult::error(
                'Claude Code CLI (claude) is not available. '
                . 'Install it with: npm install -g @anthropic-ai/claude-code',
            );
        }

        $options = ['directory' => $directory, 'output_format' => 'json'];

        $this->addOptionalString($options, $args, 'model');
        $this->addOptionalString($options, $args, 'allowed_tools');
        $this->addOptionalString($options, $args, 'permission_mode');
        $this->addOptionalString($options, $args, 'append_system_prompt');
        $this->addOptionalString($options, $args, 'session_id');

        if (isset($args['max_turns']) && $args['max_turns'] !== '') {
            $options['max_turns'] = (int) $args['max_turns'];
        }

        if (isset($args['max_budget_usd']) && $args['max_budget_usd'] !== '') {
            $options['max_budget_usd'] = (float) $args['max_budget_usd'];
        }

        if (isset($args['timeout']) && $args['timeout'] !== '') {
            $options['timeout'] = (int) $args['timeout'];
        }

        if (!empty($args['continue_session'])) {
            $options['continue_session'] = true;
        }

        if (!empty($args['dangerously_skip_permissions'])) {
            $options['dangerously_skip_permissions'] = true;
        }

        try {
            $data = $this->client->run($prompt, $options);

            $output = [
                'session_id' => $data['session_id'] ?? null,
                'result' => $data['result'] ?? '',
            ];

            if (isset($data['total_cost_usd'])) {
                $output['total_cost_usd'] = $data['total_cost_usd'];
            }

            return ToolResult::success(
                json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            );
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $target
     * @param array<string, mixed> $source
     */
    private function addOptionalString(array &$target, array $source, string $key): void
    {
        $value = trim((string) ($source[$key] ?? ''));
        if ($value !== '') {
            $target[$key] = $value;
        }
    }
}
