<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\ClaudeCode;

use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CoquiBot\Toolkits\ClaudeCode\Tool\ClaudeCodeConfigTool;
use CoquiBot\Toolkits\ClaudeCode\Tool\ClaudeCodeRunTool;
use CoquiBot\Toolkits\ClaudeCode\Tool\ClaudeCodeSessionTool;
use CoquiBot\Toolkits\ClaudeCode\Tool\ClaudeCodeStatusTool;

/**
 * Claude Code CLI toolkit for Coqui.
 *
 * Exposes four tools that drive the `claude` CLI in non-interactive mode:
 * - claude_code_run     — execute a prompt in a project directory
 * - claude_code_config  — manage CLAUDE.md and .claude/ configuration files
 * - claude_code_session — list, inspect, and delete Claude Code sessions
 * - claude_code_status  — check CLI installation, auth status, and version
 *
 * Authentication is managed by the claude CLI itself (via `claude auth login`).
 * No API keys are required in the environment.
 *
 * Auto-discovered by Coqui's ToolkitDiscovery when installed via Composer.
 */
final class ClaudeCodeToolkit implements ToolkitInterface
{
    public function __construct(
        private readonly ClaudeCodeClient $client,
    ) {}

    /**
     * Factory method for ToolkitDiscovery — no credentials needed.
     */
    public static function fromEnv(): self
    {
        return new self(client: ClaudeCodeClient::fromEnv());
    }

    public function tools(): array
    {
        return [
            (new ClaudeCodeRunTool($this->client))->build(),
            (new ClaudeCodeConfigTool())->build(),
            (new ClaudeCodeSessionTool())->build(),
            (new ClaudeCodeStatusTool($this->client))->build(),
        ];
    }

    public function guidelines(): string
    {
        return <<<'GUIDELINES'
            <CLAUDE-CODE-TOOLKIT-GUIDELINES>
            ## Claude Code Toolkit

            You have access to four tools that drive the `claude` CLI non-interactively.
            Use these to delegate complex coding tasks, multi-file edits, and project
            analysis to Claude Code as a sub-agent.

            ### Tool Overview
            - `claude_code_run`     — Send a prompt to Claude Code targeting a project directory.
                                      Captures the result and session_id. Use for any coding task.
            - `claude_code_config`  — Read or write CLAUDE.md project memory and .claude/ settings.
                                      Use to inspect/update what Claude Code knows about a project.
            - `claude_code_session` — List, inspect, or delete Claude Code sessions by directory.
                                      Use to resume previous conversations or clean up old sessions.
            - `claude_code_status`  — Check whether the claude CLI is installed, authenticated, and
                                      which version is running. Always call this first if unsure.

            ### Key Workflows

            #### First-time use in a project
            1. `claude_code_status(action: "check")` — verify CLI is available and authenticated
            2. `claude_code_config(action: "read", directory: "/path/to/project")` — check existing memory
            3. `claude_code_run(prompt: "...", directory: "/path/to/project")` — run your task

            #### Multi-turn conversation (continue a session)
            1. First call: `claude_code_run(prompt: "...", directory: "...")` — note `session_id` in result
            2. Next call: `claude_code_run(prompt: "follow-up...", directory: "...", session_id: "<id>")` — resume

            #### Read-only analysis (safe, no file changes)
            - Use `permission_mode: "plan"` — Claude Code will only plan, never execute writes

            #### Budget control
            - Set `max_budget_usd` to limit cost (e.g. 0.5 for $0.50 cap)
            - Set `max_turns` to limit agent iterations (e.g. 10 for quick tasks)

            #### Pre-approve tools to skip prompts
            - Pass `allowed_tools: "Bash,Read,Edit"` to avoid permission interruptions
            - Use `dangerously_skip_permissions: true` only when you fully trust the prompt

            ### Important Notes
            - Claude Code manages its own authentication. If `claude_code_status` reports
              "not authenticated", the user must run `claude auth login` in their terminal.
            - `session_id` is returned in every `claude_code_run` result — save it to enable
              multi-turn conversations without re-explaining context.
            - The `directory` parameter sets the working directory for all file operations.
              Always use absolute paths.
            - Project memory lives in `CLAUDE.md` at the project root. Use `claude_code_config`
              action `append` to add persistent instructions for that project.
            - Sessions are stored in `~/.claude/projects/<encoded-path>/`. Use
              `claude_code_session(action: "list")` to see all sessions for a project.
            </CLAUDE-CODE-TOOLKIT-GUIDELINES>
            GUIDELINES;
    }
}
