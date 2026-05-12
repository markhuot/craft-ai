<?php

namespace markhuot\craftai\console\controllers;

use Craft;
use craft\console\Controller;
use craft\elements\User;
use craft\helpers\Console;
use Mcp\Server\Transport\StdioTransport;
use markhuot\craftai\agent\ToolContext;
use markhuot\craftai\mcp\ServerFactory;
use markhuot\craftai\tools\ToolRegistry;
use yii\console\ExitCode;

/**
 * Stdio entrypoint for the MCP server, used to drive it from a parent process
 * (Claude Desktop, Claude Code, etc.) over JSON-RPC on stdin/stdout. Unlike
 * the HTTP transport (controllers/McpController) there is no OAuth bearer to
 * derive a Craft user from, so the launcher must pass `--user=<id|email|username>`
 * and the session runs under that user's identity. This puts stdio MCP through
 * the same per-tool permission gates that ToolRegistry::assertPermission enforces
 * for every other entrypoint — without it the registry would treat the session
 * as an anonymous guest and refuse every tool.
 */
class McpController extends Controller
{
    /**
     * @var string|null Numeric ID, username, or email of the Craft user whose
     * permissions should gate this MCP session.
     */
    public ?string $user = null;

    public function options($actionID): array
    {
        $options = parent::options($actionID);

        if ($actionID === 'serve') {
            $options[] = 'user';
        }

        return $options;
    }

    public function actionServe(): int
    {
        $identifier = is_string($this->user) ? trim($this->user) : '';
        if ($identifier === '') {
            $this->stderr(
                "craft-ai: --user=<id|username|email> is required so tool permissions can be applied to the MCP session.\n",
                Console::FG_RED,
            );

            return ExitCode::USAGE;
        }

        $identity = $this->resolveUser($identifier);
        if (! $identity instanceof User) {
            $this->stderr(
                "craft-ai: no active user matches \"{$identifier}\".\n",
                Console::FG_RED,
            );

            return ExitCode::DATAERR;
        }

        Craft::$app->getUser()->setIdentity($identity);

        /** @var ToolRegistry $registry */
        $registry = Craft::$container->get(ToolRegistry::class);
        /** @var ToolContext $toolContext */
        $toolContext = Craft::$container->get(ToolContext::class);
        $server = (new ServerFactory($registry, $toolContext))->build();
        $server->run(new StdioTransport());

        return ExitCode::OK;
    }

    /**
     * Resolve the launcher-supplied identifier to a Craft user. Numeric inputs
     * are tried as IDs first, then fall back to a username/email lookup so a
     * numeric username still resolves if no user has that ID. Only active,
     * non-locked users are accepted — pending/suspended accounts shouldn't be
     * able to launch an MCP session at all, and lifting a login lockout via a
     * stdio launcher would defeat the lockout's purpose.
     */
    protected function resolveUser(string $identifier): ?User
    {
        $users = Craft::$app->getUsers();

        $user = null;
        if (ctype_digit($identifier)) {
            $user = $users->getUserById((int) $identifier);
        }
        if (! $user instanceof User) {
            $user = $users->getUserByUsernameOrEmail($identifier);
        }

        if (! $user instanceof User) {
            return null;
        }
        if ($user->getStatus() !== User::STATUS_ACTIVE || $user->locked) {
            return null;
        }

        return $user;
    }
}
