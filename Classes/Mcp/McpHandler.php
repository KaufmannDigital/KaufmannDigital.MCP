<?php

declare(strict_types=1);

namespace KaufmannDigital\MCP\Mcp;

use KaufmannDigital\MCP\Tool\ToolInterface;
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
     * @Flow\InjectConfiguration(path="exposeErrorDetails")
     * @var bool|null
     */
    protected $exposeErrorDetails;

    /**
     * Tool arguments which are echoed in error responses to identify the affected node / property
     */
    protected const ERROR_CONTEXT_ARGUMENTS = ['nodeIdentifier', 'parentNodeIdentifier', 'identifier', 'nodeType', 'propertyName', 'workspaceName'];

    private ?array $toolInstances = null;

    public function handle(array $data): array
    {
        $id = $data['id'] ?? null;
        $method = $data['method'] ?? '';

        if (isset($data['params']) && !is_array($data['params'])) {
            return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32602, 'message' => 'Invalid params: "params" must be an object']];
        }

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
                $arguments = $params['arguments'] ?? [];
                if (!is_array($arguments)) {
                    return ['content' => [['type' => 'text', 'text' => 'Invalid arguments for tool "' . $toolName . '": "arguments" must be an object']], 'isError' => true];
                }
                try {
                    // Access is already guarded by IP allowlist + API token in the controller, so
                    // tools run without Flow authorization checks (e.g. reading non-live workspaces).
                    $content = null;
                    $this->securityContext->withoutAuthorizationChecks(function () use ($tool, $arguments, &$content) {
                        $content = $tool->execute($arguments);
                    });
                    return ['content' => $content];
                } catch (\Throwable $e) {
                    $message = $this->throwableStorage->logThrowable($e);
                    $this->logger->error('MCP tool "' . $toolName . '" failed: ' . $message, LogEnvironment::fromMethodName(__METHOD__));
                    return ['content' => [['type' => 'text', 'text' => $this->renderToolError($toolName, $arguments, $e, $message)]], 'isError' => true];
                }
            }
        }
        return ['content' => [['type' => 'text', 'text' => 'Unknown tool: ' . $toolName]], 'isError' => true];
    }

    private function renderToolError(string $toolName, array $arguments, \Throwable $exception, string $logMessage): string
    {
        // FileStorage returns "... - See also: <reference>.txt"; other storages may return something else
        $reference = preg_match('/See also: (\S+)/', $logMessage, $matches) ? $matches[1] : substr($logMessage, 0, 60);

        if ($this->exposeErrorDetails === false) {
            return 'Tool execution failed. The error has been logged (ref: ' . $reference . ').';
        }

        $lines = ['Tool "' . $toolName . '" failed: ' . $this->describeThrowable($exception)];

        $depth = 0;
        $previous = $exception->getPrevious();
        while ($previous !== null && $depth++ < 5) {
            $lines[] = 'Caused by: ' . $this->describeThrowable($previous);
            $previous = $previous->getPrevious();
        }

        $context = [];
        foreach (self::ERROR_CONTEXT_ARGUMENTS as $argumentName) {
            if (isset($arguments[$argumentName]) && is_scalar($arguments[$argumentName])) {
                $context[] = $argumentName . '=' . $arguments[$argumentName];
            }
        }
        if ($context !== []) {
            $lines[] = 'Arguments: ' . implode(', ', $context);
        }

        $lines[] = 'Log reference: ' . $reference;

        return implode(PHP_EOL, $lines);
    }

    private function describeThrowable(\Throwable $throwable): string
    {
        return get_class($throwable) . ' (code ' . $throwable->getCode() . '): ' . $throwable->getMessage();
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
