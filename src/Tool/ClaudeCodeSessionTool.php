<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\ClaudeCode\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;

/**
 * List, inspect, and delete Claude Code sessions from ~/.claude/projects/.
 */
final readonly class ClaudeCodeSessionTool
{
    private const array ACTIONS = ['list', 'inspect', 'delete'];

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'claude_code_session',
            description: 'Manage Claude Code conversation sessions. '
                . 'List sessions for a project, inspect session details, or delete old sessions. '
                . 'Sessions are stored in ~/.claude/projects/ and identified by UUID.',
            parameters: [
                new EnumParameter('action', 'Operation to perform', self::ACTIONS),
                new StringParameter(
                    'directory',
                    'Absolute path to the project directory to filter sessions by. '
                        . 'Required for list. Optional for inspect/delete.',
                    required: false,
                ),
                new StringParameter(
                    'session_id',
                    'Session UUID to inspect or delete.',
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

        return match ($action) {
            'list'    => $this->listSessions($args),
            'inspect' => $this->inspect($args),
            'delete'  => $this->delete($args),
            default   => ToolResult::error(
                "Unknown action: {$action}. Valid actions: " . implode(', ', self::ACTIONS),
            ),
        };
    }

    /**
     * @param array<string, mixed> $args
     */
    private function listSessions(array $args): ToolResult
    {
        $projectsBase = $this->projectsBasePath();
        if (!is_dir($projectsBase)) {
            return ToolResult::success('No Claude Code sessions found (directory does not exist).');
        }

        $directory = trim((string) ($args['directory'] ?? ''));
        $encodedFilter = $directory !== '' ? $this->encodeProjectPath($directory) : '';

        $sessions = [];

        $projectDirs = glob($projectsBase . '/*', GLOB_ONLYDIR) ?: [];

        foreach ($projectDirs as $projectDir) {
            $projectName = basename($projectDir);

            // Filter by project if a directory was specified
            if ($encodedFilter !== '' && $projectName !== $encodedFilter) {
                continue;
            }

            $decodedPath = $this->decodeProjectPath($projectName);

            // Session .jsonl files live directly in the project directory
            $jsonlFiles = glob($projectDir . '/*.jsonl') ?: [];

            foreach ($jsonlFiles as $jsonlFile) {
                $sessionId = basename($jsonlFile, '.jsonl');
                $meta = $this->extractSessionMeta($jsonlFile);

                $sessions[] = array_merge([
                    'session_id' => $sessionId,
                    'project_path' => $decodedPath,
                ], $meta);
            }
        }

        if ($sessions === []) {
            $msg = $encodedFilter !== ''
                ? "No sessions found for project: {$directory}"
                : 'No Claude Code sessions found.';

            return ToolResult::success($msg);
        }

        return ToolResult::success(
            json_encode(['sessions' => $sessions], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param array<string, mixed> $args
     */
    private function inspect(array $args): ToolResult
    {
        $sessionId = trim((string) ($args['session_id'] ?? ''));
        if ($sessionId === '') {
            return ToolResult::error('session_id is required for inspect.');
        }

        $jsonlFile = $this->findSessionJsonl($sessionId, trim((string) ($args['directory'] ?? '')));
        if ($jsonlFile === null) {
            return ToolResult::error("Session not found: {$sessionId}");
        }

        $handle = fopen($jsonlFile, 'r');
        if ($handle === false) {
            return ToolResult::error("Cannot read session file: {$jsonlFile}");
        }

        $lines = [];
        $count = 0;
        while (!feof($handle) && $count < 50) {
            $line = fgets($handle);
            if ($line !== false && trim($line) !== '') {
                $decoded = json_decode(trim($line), true);
                if (is_array($decoded)) {
                    $lines[] = $decoded;
                }
            }
            $count++;
        }
        fclose($handle);

        return ToolResult::success(
            json_encode([
                'session_id' => $sessionId,
                'file' => $jsonlFile,
                'entries' => count($lines),
                'sample' => array_slice($lines, 0, 5),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param array<string, mixed> $args
     */
    private function delete(array $args): ToolResult
    {
        $sessionId = trim((string) ($args['session_id'] ?? ''));
        if ($sessionId === '') {
            return ToolResult::error('session_id is required for delete.');
        }

        $directory = trim((string) ($args['directory'] ?? ''));
        $jsonlFile = $this->findSessionJsonl($sessionId, $directory);

        if ($jsonlFile === null) {
            return ToolResult::error("Session not found: {$sessionId}");
        }

        $deleted = [];

        // Delete the .jsonl file
        if (file_exists($jsonlFile) && unlink($jsonlFile)) {
            $deleted[] = basename($jsonlFile);
        }

        // Delete the session sub-directory if it exists
        $sessionDir = dirname($jsonlFile) . '/' . $sessionId;
        if (is_dir($sessionDir)) {
            $this->removeDirectory($sessionDir);
            $deleted[] = $sessionId . '/';
        }

        if ($deleted === []) {
            return ToolResult::error("Failed to delete session: {$sessionId}");
        }

        return ToolResult::success(
            "Deleted session {$sessionId}: " . implode(', ', $deleted),
        );
    }

    /**
     * Find the .jsonl file for a given session UUID.
     */
    private function findSessionJsonl(string $sessionId, string $directory): ?string
    {
        $projectsBase = $this->projectsBasePath();
        if (!is_dir($projectsBase)) {
            return null;
        }

        // If directory is specified, look only in that project folder
        if ($directory !== '') {
            $encoded = $this->encodeProjectPath($directory);
            $candidate = $projectsBase . '/' . $encoded . '/' . $sessionId . '.jsonl';

            return file_exists($candidate) ? $candidate : null;
        }

        // Search all project directories
        $projectDirs = glob($projectsBase . '/*', GLOB_ONLYDIR) ?: [];
        foreach ($projectDirs as $projectDir) {
            $candidate = $projectDir . '/' . $sessionId . '.jsonl';
            if (file_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Extract basic metadata from the first line of a session .jsonl file.
     *
     * @return array<string, mixed>
     */
    private function extractSessionMeta(string $jsonlFile): array
    {
        $meta = [
            'size' => filesize($jsonlFile),
            'modified' => date('Y-m-d H:i:s', (int) filemtime($jsonlFile)),
        ];

        $handle = fopen($jsonlFile, 'r');
        if ($handle === false) {
            return $meta;
        }

        $firstLine = fgets($handle);
        fclose($handle);

        if ($firstLine === false) {
            return $meta;
        }

        $data = json_decode(trim($firstLine), true);
        if (is_array($data)) {
            if (isset($data['model'])) {
                $meta['model'] = $data['model'];
            }
            if (isset($data['timestamp'])) {
                $meta['started'] = $data['timestamp'];
            }
        }

        return $meta;
    }

    /**
     * Get the base path for Claude Code projects: ~/.claude/projects/
     */
    private function projectsBasePath(): string
    {
        $home = (string) ($_SERVER['HOME'] ?? getenv('HOME') ?: '');

        return $home . '/.claude/projects';
    }

    /**
     * Encode a project path the way Claude Code does: replace / with -
     */
    private function encodeProjectPath(string $path): string
    {
        return ltrim(str_replace('/', '-', $path), '-');
    }

    /**
     * Decode a Claude Code project directory name back to a path.
     */
    private function decodeProjectPath(string $encoded): string
    {
        return '/' . ltrim(str_replace('-', '/', $encoded), '/');
    }

    /**
     * Recursively remove a directory and its contents.
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item instanceof \SplFileInfo) {
                if ($item->isDir()) {
                    rmdir($item->getPathname());
                } else {
                    unlink($item->getPathname());
                }
            }
        }

        rmdir($dir);
    }
}
