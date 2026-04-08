<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\ClaudeCode\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;

/**
 * Manage Claude Code project memory (CLAUDE.md) and .claude/ configuration files.
 */
final readonly class ClaudeCodeConfigTool
{
    private const array ACTIONS = ['read', 'write', 'append', 'list_files'];

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'claude_code_config',
            description: 'Manage Claude Code project memory and configuration. '
                . 'Read or write CLAUDE.md (project-level instructions for Claude Code) '
                . 'and inspect .claude/ directory contents.',
            parameters: [
                new EnumParameter('action', 'Operation to perform', self::ACTIONS),
                new StringParameter('directory', 'Absolute path to the project directory'),
                new StringParameter(
                    'content',
                    'Content to write or append. Required for write and append actions.',
                    required: false,
                ),
                new StringParameter(
                    'file',
                    'Relative file path within the project (defaults to "CLAUDE.md"). '
                        . 'Examples: "CLAUDE.md", ".claude/settings.json", ".claude/rules/style.md"',
                    required: false,
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
        $action = (string) ($args['action'] ?? '');
        $directory = trim((string) ($args['directory'] ?? ''));

        if ($directory === '' || !is_dir($directory)) {
            return ToolResult::error('directory is required and must exist.');
        }

        return match ($action) {
            'read'       => $this->read($args, $directory),
            'write'      => $this->write($args, $directory),
            'append'     => $this->append($args, $directory),
            'list_files' => $this->listFiles($directory),
            default      => ToolResult::error(
                "Unknown action: {$action}. Valid actions: " . implode(', ', self::ACTIONS),
            ),
        };
    }

    /**
     * @param array<string, mixed> $args
     */
    private function read(array $args, string $directory): ToolResult
    {
        $file = $this->resolveFile($args, $directory);
        if (!is_readable($file)) {
            $relative = (string) ($args['file'] ?? 'CLAUDE.md');

            return ToolResult::success("File not found: {$relative}");
        }

        $content = file_get_contents($file);

        return ToolResult::success($content !== false ? $content : 'Unable to read file.');
    }

    /**
     * @param array<string, mixed> $args
     */
    private function write(array $args, string $directory): ToolResult
    {
        $content = (string) ($args['content'] ?? '');
        if ($content === '') {
            return ToolResult::error('content is required for write action.');
        }

        $file = $this->resolveFile($args, $directory);

        if (!$this->ensureParentDirectory($file)) {
            return ToolResult::error("Cannot create directory for: {$file}");
        }

        if (file_put_contents($file, $content, LOCK_EX) === false) {
            return ToolResult::error("Failed to write file: {$file}");
        }

        $relative = $this->relativePath($args);

        return ToolResult::success("Written {$relative} (" . strlen($content) . ' bytes).');
    }

    /**
     * @param array<string, mixed> $args
     */
    private function append(array $args, string $directory): ToolResult
    {
        $content = (string) ($args['content'] ?? '');
        if ($content === '') {
            return ToolResult::error('content is required for append action.');
        }

        $file = $this->resolveFile($args, $directory);

        if (!$this->ensureParentDirectory($file)) {
            return ToolResult::error("Cannot create directory for: {$file}");
        }

        if (file_put_contents($file, "\n" . $content, FILE_APPEND | LOCK_EX) === false) {
            return ToolResult::error("Failed to append to file: {$file}");
        }

        $relative = $this->relativePath($args);

        return ToolResult::success("Appended to {$relative} (" . strlen($content) . ' bytes added).');
    }

    private function listFiles(string $directory): ToolResult
    {
        $claudeDir = rtrim($directory, '/') . '/.claude';

        $files = [];

        // Always include CLAUDE.md if it exists
        $claudeMd = rtrim($directory, '/') . '/CLAUDE.md';
        if (file_exists($claudeMd)) {
            $files[] = [
                'path' => 'CLAUDE.md',
                'size' => filesize($claudeMd),
                'modified' => date('Y-m-d H:i:s', (int) filemtime($claudeMd)),
            ];
        }

        if (is_dir($claudeDir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($claudeDir, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile()) {
                    $relativePath = '.claude/' . ltrim(
                        str_replace($claudeDir, '', $file->getPathname()),
                        '/',
                    );
                    $files[] = [
                        'path' => $relativePath,
                        'size' => $file->getSize(),
                        'modified' => date('Y-m-d H:i:s', (int) $file->getMTime()),
                    ];
                }
            }
        }

        if ($files === []) {
            return ToolResult::success('No Claude Code configuration files found in this project.');
        }

        return ToolResult::success(
            json_encode(['files' => $files], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Resolve the target file path, defaulting to CLAUDE.md.
     * Validates that the path stays within the project directory.
     *
     * @param array<string, mixed> $args
     */
    private function resolveFile(array $args, string $directory): string
    {
        $relativePath = trim((string) ($args['file'] ?? ''));

        if ($relativePath === '') {
            $relativePath = 'CLAUDE.md';
        }

        // Prevent path traversal
        $relativePath = ltrim(str_replace(['../', '..' . DIRECTORY_SEPARATOR], '', $relativePath), '/');

        return rtrim($directory, '/') . '/' . $relativePath;
    }

    /**
     * @param array<string, mixed> $args
     */
    private function relativePath(array $args): string
    {
        $file = trim((string) ($args['file'] ?? ''));

        return $file !== '' ? $file : 'CLAUDE.md';
    }

    private function ensureParentDirectory(string $filePath): bool
    {
        $dir = dirname($filePath);
        if (is_dir($dir)) {
            return true;
        }

        return mkdir($dir, 0755, true);
    }
}
