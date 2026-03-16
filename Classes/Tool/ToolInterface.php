<?php

declare(strict_types=1);

namespace KaufmannDigital\MCP\Tool;

interface ToolInterface
{
    public function getDefinition(): array;

    public function execute(array $args): array;
}
