<?php

declare(strict_types=1);

namespace KaufmannDigital\MCP\Mcp;

use KaufmannDigital\MCP\Tool\ToolInterface;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\ContentRepository\Domain\Repository\WorkspaceRepository;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Log\ThrowableStorageInterface;
use Neos\Flow\Log\Utility\LogEnvironment;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Flow\Reflection\ReflectionService;
use Neos\Flow\Security\Context as SecurityContext;
use Psr\Log\LoggerInterface;

/**
 * @Flow\Scope("singleton")
 */
class McpHandler
{
    protected const PROTOCOL_VERSION = '2024-11-05';

    /**
     * @Flow\Inject
     * @var ReflectionService
     */
    protected $reflectionService;

    /**
     * @Flow\Inject
     * @var ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @Flow\Inject
     * @var ThrowableStorageInterface
     */
    protected $throwableStorage;

    /**
     * @Flow\Inject
     * @var SecurityContext
     */
    protected $securityContext;

    /**
     * @Flow\Inject
     * @var WorkspaceRepository
     */
    protected $workspaceRepository;

    private ?array $toolInstances = null;

    public function handle(array $data): array
    {
        $id = $data['id'] ?? null;
        $method = $data['method'] ?? '';

        $result = match ($method) {
            'initialize' => $this->handleInitialize(),
            'tools/list' => $this->handleToolsList(),
            'tools/call' => $this->handleToolCall($data['params'] ?? []),
            'ping' => [],
            default => null,
        };

        if ($result === null) {
            return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => 'Method not found: ' . $method]];
        }

        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private function handleInitialize(): array
    {
        return [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => ['tools' => (object)[]],
            'serverInfo' => ['name' => 'neos-mcp', 'version' => '1.0.0'],
        ];
    }

    private function handleToolsList(): array
    {
        return ['tools' => array_map(fn($t) => $t->getDefinition(), $this->tools())];
    }

    private function handleToolCall(array $params): array
    {
        $toolName = $params['name'] ?? '';
        foreach ($this->tools() as $tool) {
            if ($tool->getDefinition()['name'] === $toolName) {
                $workspaceError = $this->validateWorkspace($params['arguments']['workspaceName'] ?? null);
                if ($workspaceError !== null) {
                    return ['content' => [['type' => 'text', 'text' => $workspaceError]], 'isError' => true];
                }
                try {
                    // Access is already guarded by IP allowlist + API token in the controller, so
                    // tools run without Flow authorization checks (e.g. reading non-live workspaces).
                    $content = null;
                    $this->securityContext->withoutAuthorizationChecks(function () use ($tool, $params, &$content) {
                        $content = $tool->execute($params['arguments'] ?? []);
                    });
                    return ['content' => $content];
                } catch (\Throwable $e) {
                    $message = $this->throwableStorage->logThrowable($e);
                    $this->logger->error('MCP tool "' . $toolName . '" failed: ' . $message, LogEnvironment::fromMethodName(__METHOD__));
                    return ['content' => [['type' => 'text', 'text' => 'Tool execution failed. The error has been logged (ref: ' . substr($message, 0, 60) . ').']], 'isError' => true];
                }
            }
        }
        return ['content' => [['type' => 'text', 'text' => 'Unknown tool: ' . $toolName]], 'isError' => true];
    }

    /**
     * Neos silently creates a workspace when a ContentContext is built for an unknown workspace name,
     * so a typo would create a new workspace instead of failing. Reject unknown names up front.
     */
    private function validateWorkspace(mixed $workspaceName): ?string
    {
        if ($workspaceName === null || $workspaceName === '') {
            return null;
        }
        if (!is_string($workspaceName)) {
            return 'Invalid workspaceName: expected a string';
        }
        if ($this->workspaceRepository->findByIdentifier($workspaceName) !== null) {
            return null;
        }
        $existing = array_map(fn(Workspace $workspace) => $workspace->getName(), $this->workspaceRepository->findAll()->toArray());
        sort($existing);
        return 'Workspace "' . $workspaceName . '" does not exist. Existing workspaces: ' . implode(', ', $existing);
    }

    private function tools(): array
    {
        if ($this->toolInstances === null) {
            $classNames = $this->reflectionService->getAllImplementationClassNamesForInterface(ToolInterface::class);
            $this->toolInstances = array_map(fn($className) => $this->objectManager->get($className), $classNames);
        }
        return $this->toolInstances;
    }
}
