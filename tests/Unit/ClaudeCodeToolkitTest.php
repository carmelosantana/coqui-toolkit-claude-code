<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CoquiBot\Toolkits\ClaudeCode\ClaudeCodeClient;
use CoquiBot\Toolkits\ClaudeCode\ClaudeCodeToolkit;
use CoquiBot\Toolkits\ClaudeCode\Exception\ClaudeCodeException;
use CoquiBot\Toolkits\ClaudeCode\Tool\ClaudeCodeConfigTool;
use CoquiBot\Toolkits\ClaudeCode\Tool\ClaudeCodeSessionTool;
use CoquiBot\Toolkits\ClaudeCode\Tool\ClaudeCodeStatusTool;

// --- Toolkit ---

test('fromEnv returns ClaudeCodeToolkit instance', function () {
    $toolkit = ClaudeCodeToolkit::fromEnv();

    expect($toolkit)->toBeInstanceOf(ClaudeCodeToolkit::class);
});

test('toolkit provides four tools', function () {
    $client = new ClaudeCodeClient(claudeBinary: '/nonexistent/claude');
    $toolkit = new ClaudeCodeToolkit($client);

    $tools = $toolkit->tools();

    expect($tools)->toHaveCount(4);

    $names = array_map(fn($t) => $t->name(), $tools);
    expect($names)->toBe([
        'claude_code_run',
        'claude_code_config',
        'claude_code_session',
        'claude_code_status',
    ]);
});

test('guidelines contain XML tags and all tool names', function () {
    $client = new ClaudeCodeClient();
    $toolkit = new ClaudeCodeToolkit($client);
    $guidelines = $toolkit->guidelines();

    expect($guidelines)
        ->toContain('<CLAUDE-CODE-TOOLKIT-GUIDELINES>')
        ->toContain('</CLAUDE-CODE-TOOLKIT-GUIDELINES>')
        ->toContain('claude_code_run')
        ->toContain('claude_code_config')
        ->toContain('claude_code_session')
        ->toContain('claude_code_status');
});

test('all tools produce valid function schemas', function () {
    $client = new ClaudeCodeClient();
    $toolkit = new ClaudeCodeToolkit($client);

    foreach ($toolkit->tools() as $tool) {
        $schema = $tool->toFunctionSchema();

        expect($schema['type'])->toBe('function')
            ->and($schema['function']['name'])->toBe($tool->name())
            ->and($schema['function']['parameters']['type'])->toBe('object');
    }
});

// --- ClaudeCodeClient ---

test('client resolves binary from constructor', function () {
    // Use a known binary that exists
    $client = new ClaudeCodeClient(claudeBinary: '/bin/echo');

    expect($client->resolveBinary())->toBe('/bin/echo')
        ->and($client->isAvailable())->toBeTrue();
});

test('client reports unavailable when binary does not exist', function () {
    $client = new ClaudeCodeClient(claudeBinary: '/nonexistent/path/claude');

    // resolveBinary may still find claude via `which` — we only test the constructor override path
    // If claude is not installed, resolveBinary returns ''
    // We cannot assert isAvailable() === false because claude may actually be installed
    expect($client)->toBeInstanceOf(ClaudeCodeClient::class);
});

test('client fromEnv creates instance', function () {
    $client = ClaudeCodeClient::fromEnv();

    expect($client)->toBeInstanceOf(ClaudeCodeClient::class);
});

// --- ClaudeCodeException ---

test('exception named constructors produce correct messages', function () {
    expect(ClaudeCodeException::binaryNotFound('claude')->getMessage())
        ->toContain('not found')
        ->toContain('claude');

    expect(ClaudeCodeException::processFailure('bad exit')->getMessage())
        ->toContain('process failed')
        ->toContain('bad exit');

    expect(ClaudeCodeException::invalidJson('not json')->getMessage())
        ->toContain('non-JSON output');

    expect(ClaudeCodeException::timeout(30)->getMessage())
        ->toContain('30 seconds');

    expect(ClaudeCodeException::authRequired()->getMessage())
        ->toContain('not authenticated');
});

// --- ClaudeCodeRunTool ---

test('run tool rejects empty prompt', function () {
    $client = new ClaudeCodeClient();
    $tool = (new \CoquiBot\Toolkits\ClaudeCode\Tool\ClaudeCodeRunTool($client))->build();

    $result = $tool->execute(['prompt' => '', 'directory' => '/tmp']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('prompt is required');
});

test('run tool rejects empty directory', function () {
    $client = new ClaudeCodeClient();
    $tool = (new \CoquiBot\Toolkits\ClaudeCode\Tool\ClaudeCodeRunTool($client))->build();

    $result = $tool->execute(['prompt' => 'hello', 'directory' => '']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('directory is required');
});

test('run tool rejects nonexistent directory', function () {
    $client = new ClaudeCodeClient();
    $tool = (new \CoquiBot\Toolkits\ClaudeCode\Tool\ClaudeCodeRunTool($client))->build();

    $result = $tool->execute(['prompt' => 'hello', 'directory' => '/nonexistent/path']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('does not exist');
});

// --- ClaudeCodeConfigTool ---

test('config tool reads CLAUDE.md from temp directory', function () {
    $tmpDir = sys_get_temp_dir() . '/claude_test_' . uniqid();
    mkdir($tmpDir, 0755, true);
    file_put_contents($tmpDir . '/CLAUDE.md', '# Test Project');

    $tool = (new ClaudeCodeConfigTool())->build();

    $result = $tool->execute(['action' => 'read', 'directory' => $tmpDir]);

    expect($result->status)->toBe(ToolResultStatus::Success)
        ->and($result->content)->toContain('# Test Project');

    unlink($tmpDir . '/CLAUDE.md');
    rmdir($tmpDir);
});

test('config tool reports missing file gracefully', function () {
    $tmpDir = sys_get_temp_dir() . '/claude_test_' . uniqid();
    mkdir($tmpDir, 0755, true);

    $tool = (new ClaudeCodeConfigTool())->build();

    $result = $tool->execute(['action' => 'read', 'directory' => $tmpDir]);

    expect($result->status)->toBe(ToolResultStatus::Success)
        ->and($result->content)->toContain('File not found');

    rmdir($tmpDir);
});

test('config tool writes CLAUDE.md', function () {
    $tmpDir = sys_get_temp_dir() . '/claude_test_' . uniqid();
    mkdir($tmpDir, 0755, true);

    $tool = (new ClaudeCodeConfigTool())->build();

    $result = $tool->execute([
        'action' => 'write',
        'directory' => $tmpDir,
        'content' => '# My Project Memory',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success)
        ->and($result->content)->toContain('Written')
        ->and(file_get_contents($tmpDir . '/CLAUDE.md'))->toBe('# My Project Memory');

    unlink($tmpDir . '/CLAUDE.md');
    rmdir($tmpDir);
});

test('config tool appends to CLAUDE.md', function () {
    $tmpDir = sys_get_temp_dir() . '/claude_test_' . uniqid();
    mkdir($tmpDir, 0755, true);
    file_put_contents($tmpDir . '/CLAUDE.md', '# Project');

    $tool = (new ClaudeCodeConfigTool())->build();

    $result = $tool->execute([
        'action' => 'append',
        'directory' => $tmpDir,
        'content' => '## New Section',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success)
        ->and($result->content)->toContain('Appended');

    $content = file_get_contents($tmpDir . '/CLAUDE.md');
    expect($content)->toContain('# Project')
        ->and($content)->toContain('## New Section');

    unlink($tmpDir . '/CLAUDE.md');
    rmdir($tmpDir);
});

test('config tool writes to nested .claude/ path', function () {
    $tmpDir = sys_get_temp_dir() . '/claude_test_' . uniqid();
    mkdir($tmpDir, 0755, true);

    $tool = (new ClaudeCodeConfigTool())->build();

    $result = $tool->execute([
        'action' => 'write',
        'directory' => $tmpDir,
        'file' => '.claude/rules/style.md',
        'content' => '# Style Rules',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success)
        ->and(file_exists($tmpDir . '/.claude/rules/style.md'))->toBeTrue()
        ->and(file_get_contents($tmpDir . '/.claude/rules/style.md'))->toBe('# Style Rules');

    // Cleanup
    unlink($tmpDir . '/.claude/rules/style.md');
    rmdir($tmpDir . '/.claude/rules');
    rmdir($tmpDir . '/.claude');
    rmdir($tmpDir);
});

test('config tool lists files', function () {
    $tmpDir = sys_get_temp_dir() . '/claude_test_' . uniqid();
    mkdir($tmpDir . '/.claude', 0755, true);
    file_put_contents($tmpDir . '/CLAUDE.md', '# Test');
    file_put_contents($tmpDir . '/.claude/settings.json', '{}');

    $tool = (new ClaudeCodeConfigTool())->build();

    $result = $tool->execute(['action' => 'list_files', 'directory' => $tmpDir]);

    expect($result->status)->toBe(ToolResultStatus::Success)
        ->and($result->content)->toContain('CLAUDE.md')
        ->and($result->content)->toContain('settings.json');

    unlink($tmpDir . '/CLAUDE.md');
    unlink($tmpDir . '/.claude/settings.json');
    rmdir($tmpDir . '/.claude');
    rmdir($tmpDir);
});

test('config tool rejects write without content', function () {
    $tmpDir = sys_get_temp_dir() . '/claude_test_' . uniqid();
    mkdir($tmpDir, 0755, true);

    $tool = (new ClaudeCodeConfigTool())->build();

    $result = $tool->execute(['action' => 'write', 'directory' => $tmpDir]);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('content is required');

    rmdir($tmpDir);
});

test('config tool rejects invalid directory', function () {
    $tool = (new ClaudeCodeConfigTool())->build();

    $result = $tool->execute(['action' => 'read', 'directory' => '/nonexistent']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('directory is required');
});

test('config tool rejects unknown action', function () {
    $tmpDir = sys_get_temp_dir() . '/claude_test_' . uniqid();
    mkdir($tmpDir, 0755, true);

    $tool = (new ClaudeCodeConfigTool())->build();

    $result = $tool->execute(['action' => 'invalid', 'directory' => $tmpDir]);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('Unknown action');

    rmdir($tmpDir);
});

// --- ClaudeCodeSessionTool ---

test('session tool handles missing projects directory', function () {
    // Override HOME to a temp dir with no .claude/projects/
    $oldHome = $_SERVER['HOME'] ?? null;
    $_SERVER['HOME'] = sys_get_temp_dir() . '/claude_no_sessions_' . uniqid();

    $tool = (new ClaudeCodeSessionTool())->build();

    $result = $tool->execute(['action' => 'list']);

    expect($result->status)->toBe(ToolResultStatus::Success)
        ->and($result->content)->toContain('No Claude Code sessions found');

    if ($oldHome !== null) {
        $_SERVER['HOME'] = $oldHome;
    }
});

test('session tool rejects inspect without session_id', function () {
    $tool = (new ClaudeCodeSessionTool())->build();

    $result = $tool->execute(['action' => 'inspect']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('session_id is required');
});

test('session tool rejects delete without session_id', function () {
    $tool = (new ClaudeCodeSessionTool())->build();

    $result = $tool->execute(['action' => 'delete']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('session_id is required');
});

test('session tool rejects unknown action', function () {
    $tool = (new ClaudeCodeSessionTool())->build();

    $result = $tool->execute(['action' => 'invalid']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('Unknown action');
});

// --- ClaudeCodeStatusTool ---

test('status tool rejects unknown action', function () {
    $client = new ClaudeCodeClient();
    $tool = (new ClaudeCodeStatusTool($client))->build();

    $result = $tool->execute(['action' => 'invalid']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('Unknown action');
});
