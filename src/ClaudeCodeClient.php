<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\ClaudeCode;

use CoquiBot\Toolkits\ClaudeCode\Exception\ClaudeCodeException;

/**
 * Process wrapper for the Claude Code CLI (`claude`).
 *
 * Handles binary resolution, command construction, subprocess execution
 * via proc_open(), timeout enforcement, and JSON output parsing.
 *
 * All credential management is handled by the claude CLI itself (via
 * `claude auth login`). This client requires no API keys.
 */
final class ClaudeCodeClient
{
    private const int DEFAULT_TIMEOUT = 300;
    private const int MAX_OUTPUT_BYTES = 524_288; // 512 KB

    /** Cached resolved binary path. */
    private string $resolvedBinary = '';

    public function __construct(
        private readonly string $claudeBinary = '',
    ) {}

    /**
     * Factory method for ToolkitDiscovery — no env credentials needed.
     */
    public static function fromEnv(): self
    {
        $binary = getenv('CLAUDE_CODE_BINARY');

        return new self(
            claudeBinary: $binary !== false ? $binary : '',
        );
    }

    /**
     * Run a prompt against Claude Code and return the parsed JSON response.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     * @throws ClaudeCodeException
     */
    public function run(string $prompt, array $options = []): array
    {
        $binary = $this->resolveBinary();
        if ($binary === '') {
            throw ClaudeCodeException::binaryNotFound('claude');
        }

        $args = $this->buildArgs($prompt, $options);
        $command = escapeshellarg($binary) . ' ' . implode(' ', $args);

        $cwd = isset($options['directory']) && is_string($options['directory']) && $options['directory'] !== ''
            ? $options['directory']
            : (getcwd() ?: '.');

        $timeout = isset($options['timeout']) && is_int($options['timeout'])
            ? $options['timeout']
            : self::DEFAULT_TIMEOUT;

        [$exitCode, $stdout, $stderr] = $this->execute($command, $cwd, $timeout);

        if ($exitCode === 124) {
            throw ClaudeCodeException::timeout($timeout);
        }

        $outputFormat = (string) ($options['output_format'] ?? 'json');

        if ($outputFormat === 'json') {
            return $this->parseJsonOutput($stdout, $stderr, $exitCode);
        }

        // text format — wrap in a normalized array
        return [
            'result' => $stdout !== '' ? $stdout : $stderr,
            'exit_code' => $exitCode,
        ];
    }

    /**
     * Run a non-prompt claude subcommand (e.g. --version, auth status).
     *
     * @return array{exit_code: int, stdout: string, stderr: string}
     * @throws ClaudeCodeException
     */
    public function runRaw(string ...$args): array
    {
        $binary = $this->resolveBinary();
        if ($binary === '') {
            throw ClaudeCodeException::binaryNotFound('claude');
        }

        $parts = [escapeshellarg($binary)];
        foreach ($args as $arg) {
            $parts[] = escapeshellarg($arg);
        }
        $command = implode(' ', $parts);
        $cwd = getcwd() ?: '.';

        [$exitCode, $stdout, $stderr] = $this->execute($command, $cwd, 15);

        return [
            'exit_code' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }

    /**
     * Resolve the absolute path to the claude binary.
     *
     * Tries the constructor override, `which claude`, then common install paths.
     */
    public function resolveBinary(): string
    {
        if ($this->resolvedBinary !== '') {
            return $this->resolvedBinary;
        }

        // Honor constructor/env override first
        if ($this->claudeBinary !== '' && file_exists($this->claudeBinary)) {
            $this->resolvedBinary = $this->claudeBinary;

            return $this->resolvedBinary;
        }

        // Try which
        $which = trim((string) shell_exec('which claude 2>/dev/null'));
        if ($which !== '' && file_exists($which)) {
            $this->resolvedBinary = $which;

            return $which;
        }

        // Common npm global install locations
        $home = (string) ($_SERVER['HOME'] ?? getenv('HOME') ?: '');
        $candidates = [
            '/usr/local/bin/claude',
            '/opt/homebrew/bin/claude',
        ];

        if ($home !== '') {
            array_unshift($candidates, $home . '/.local/bin/claude');
            array_unshift($candidates, $home . '/.npm-packages/bin/claude');
        }

        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                $this->resolvedBinary = $candidate;

                return $candidate;
            }
        }

        return '';
    }

    /**
     * Check whether the claude CLI is available on this system.
     */
    public function isAvailable(): bool
    {
        return $this->resolveBinary() !== '';
    }

    /**
     * Build the CLI argument list for a `claude -p` invocation.
     *
     * @param array<string, mixed> $options
     * @return list<string>
     */
    private function buildArgs(string $prompt, array $options): array
    {
        $outputFormat = (string) ($options['output_format'] ?? 'json');

        // Core flags
        $args = [
            '--print', escapeshellarg($prompt),
            '--output-format', escapeshellarg($outputFormat),
        ];

        // Model selection
        if (isset($options['model']) && is_string($options['model']) && $options['model'] !== '') {
            $args[] = '--model';
            $args[] = escapeshellarg($options['model']);
        }

        // Turn limit
        if (isset($options['max_turns']) && is_int($options['max_turns'])) {
            $args[] = '--max-turns';
            $args[] = (string) $options['max_turns'];
        }

        // Budget limit
        if (isset($options['max_budget_usd']) && is_numeric($options['max_budget_usd'])) {
            $args[] = '--max-budget-usd';
            $args[] = (string) $options['max_budget_usd'];
        }

        // Allowed tools
        if (isset($options['allowed_tools']) && is_string($options['allowed_tools']) && $options['allowed_tools'] !== '') {
            $args[] = '--allowedTools';
            $args[] = escapeshellarg($options['allowed_tools']);
        }

        // Permission mode
        if (isset($options['permission_mode']) && is_string($options['permission_mode']) && $options['permission_mode'] !== '') {
            $args[] = '--permission-mode';
            $args[] = escapeshellarg($options['permission_mode']);
        }

        // System prompt extension
        if (isset($options['append_system_prompt']) && is_string($options['append_system_prompt']) && $options['append_system_prompt'] !== '') {
            $args[] = '--append-system-prompt';
            $args[] = escapeshellarg($options['append_system_prompt']);
        }

        // Session continuation
        if (isset($options['session_id']) && is_string($options['session_id']) && $options['session_id'] !== '') {
            $args[] = '--resume';
            $args[] = escapeshellarg($options['session_id']);
        } elseif (!empty($options['continue_session'])) {
            $args[] = '--continue';
        }

        // Additional directories
        if (isset($options['add_dir']) && is_string($options['add_dir']) && $options['add_dir'] !== '') {
            $args[] = '--add-dir';
            $args[] = escapeshellarg($options['add_dir']);
        }

        // Skip permissions (dangerous!)
        if (!empty($options['dangerously_skip_permissions'])) {
            $args[] = '--dangerously-skip-permissions';
        }

        return $args;
    }

    /**
     * Parse the JSON output from claude --output-format json.
     *
     * @return array<string, mixed>
     * @throws ClaudeCodeException
     */
    private function parseJsonOutput(string $stdout, string $stderr, int $exitCode): array
    {
        $stdout = trim($stdout);

        if ($stdout === '') {
            if ($exitCode !== 0) {
                if (str_contains($stderr, 'auth') || str_contains($stderr, 'login')) {
                    throw ClaudeCodeException::authRequired();
                }

                throw ClaudeCodeException::processFailure($stderr !== '' ? $stderr : "Exit code {$exitCode}");
            }

            return ['result' => '', 'session_id' => null];
        }

        // Take the last non-empty line in case there are debug/progress lines
        $lines = array_values(array_filter(array_map('trim', explode("\n", $stdout))));

        if ($lines === []) {
            throw ClaudeCodeException::invalidJson($stdout);
        }

        $lastLine = $lines[count($lines) - 1];

        try {
            $data = json_decode($lastLine, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ClaudeCodeException::invalidJson($lastLine);
        }

        if (!is_array($data)) {
            throw ClaudeCodeException::invalidJson($lastLine);
        }

        return $data;
    }

    /**
     * Execute a shell command via proc_open with timeout and output truncation.
     *
     * @return array{int, string, string}
     */
    private function execute(string $command, string $cwd, int $timeout): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, $cwd);

        if (!is_resource($process)) {
            return [1, '', 'Failed to start process: ' . $command];
        }

        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $startTime = time();

        while (true) {
            $status = proc_get_status($process);

            $out = stream_get_contents($pipes[1]) ?: '';
            $err = stream_get_contents($pipes[2]) ?: '';

            $stdout .= $out;
            $stderr .= $err;

            // Truncate early to avoid memory exhaustion
            if (strlen($stdout) > self::MAX_OUTPUT_BYTES) {
                $stdout = substr($stdout, 0, self::MAX_OUTPUT_BYTES)
                    . "\n[Output truncated at " . self::MAX_OUTPUT_BYTES . ' bytes]';
            }

            if (!$status['running']) {
                break;
            }

            if ($timeout > 0 && (time() - $startTime) >= $timeout) {
                proc_terminate($process, 15); // SIGTERM
                usleep(200_000);
                proc_terminate($process, 9); // SIGKILL
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                return [124, $stdout, $stderr];
            }

            usleep(50_000); // 50ms poll
        }

        // Drain remaining output
        $stdout .= stream_get_contents($pipes[1]) ?: '';
        $stderr .= stream_get_contents($pipes[2]) ?: '';

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [$exitCode, trim($stdout), trim($stderr)];
    }
}
