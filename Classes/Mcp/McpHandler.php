<?php

declare(strict_types=1);

namespace KaufmannDigital\MCP\Mcp;

use KaufmannDigital\MCP\Tool\ToolInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Log\ThrowableStorageInterface;
use Neos\Flow\Log\Utility\LogEnvironment;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Flow\Reflection\ReflectionService;
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
                try {
                    return ['content' => $tool->execute($params['arguments'] ?? [])];
                } catch (\Throwable $e) {
                    $message = $this->throwableStorage->logThrowable($e);
                    $this->logger->error('MCP tool "' . $toolName . '" failed: ' . $message, LogEnvironment::fromMethodName(__METHOD__));
                    return ['content' => [['type' => 'text', 'text' => 'Tool execution failed. The error has been logged (ref: ' . substr($message, 0, 60) . ').']], 'isError' => true];
                }
            }
        }
        return ['content' => [['type' => 'text', 'text' => 'Unknown tool: ' . $toolName]], 'isError' => true];
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
