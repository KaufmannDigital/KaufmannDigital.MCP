<?php

declare(strict_types=1);

namespace KaufmannDigital\MCP\Controller;

use KaufmannDigital\MCP\Mcp\McpHandler;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\Helper\MediaTypeHelper;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Mvc\ActionResponse;
use Neos\Flow\Mvc\Controller\ActionController;

class McpController extends ActionController
{
    public function processRequest(ActionRequest $request, ActionResponse $response): void
    {
        // Accept whatever the client accepts — MCP content type is set explicitly per action
        $this->supportedMediaTypes = MediaTypeHelper::determineAcceptedMediaTypes($request->getHttpRequest());

        parent::processRequest($request, $response);
    }

    /**
     * @Flow\Inject
     * @var McpHandler
     */
    protected $mcpHandler;

    /**
     * @Flow\InjectConfiguration(path="Token")
     * @var string
     */
    protected $token;

    /**
     * @Flow\InjectConfiguration(path="allowedIps")
     * @var array
     */
    protected $allowedIps;

    public function initializeAction(): void
    {
        // IP allowlist check (before token to fail fast).
        // Empty list = block all. Must explicitly allow IPs or CIDRs.
        $remoteIp = $this->request->getHttpRequest()->getServerParams()['REMOTE_ADDR'] ?? '';
        if (!$this->isIpAllowed($remoteIp)) {
            $this->throwStatus(403, 'Forbidden');
        }

        if (empty($this->token)) {
            $this->throwStatus(401, 'Unauthorized');
        }

        $authHeader = current($this->request->getHttpRequest()->getHeader('Authorization'));
        $givenToken = null;

        if (is_string($authHeader) && str_starts_with($authHeader, 'Bearer ')) {
            $givenToken = substr($authHeader, 7);
        }

        if (empty($givenToken)) {
            $this->throwStatus(401, 'Unauthorized');
        }

        if (!hash_equals((string)$this->token, $givenToken)) {
            $this->throwStatus(403, 'Forbidden');
        }
    }

    #[Flow\SkipCsrfProtection]
    public function indexAction(): string
    {
        $body = (string)$this->request->getHttpRequest()->getBody();
        $data = json_decode($body, true);

        $this->response->setContentType('application/json');

        if (!is_array($data)) {
            $this->response->setStatusCode(400);
            return $this->toJson([
                'jsonrpc' => '2.0',
                'error' => ['code' => -32700, 'message' => 'Parse error'],
                'id' => null,
            ]);
        }

        // Notifications (no "id" field) require no response body
        if (!array_key_exists('id', $data)) {
            $this->throwStatus(202);
        }

        return $this->toJson($this->mcpHandler->handle($data));
    }

    // GET /mcp — SSE not supported yet, return 405
    #[Flow\SkipCsrfProtection]
    public function streamAction(): string
    {
        $this->response->setStatusCode(405);
        $this->response->setContentType('application/json');
        return $this->toJson(['error' => 'SSE streaming not supported. Use POST.']);
    }

    protected function toJson(mixed $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function isIpAllowed(string $remoteIp): bool
    {
        if (empty($this->allowedIps)) {
            return false;
        }

        foreach ($this->allowedIps as $entry) {
            if (str_contains($entry, '/')) {
                if ($this->ipMatchesCidr($remoteIp, $entry)) {
                    return true;
                }
            } elseif ($remoteIp === $entry) {
                return true;
            }
        }

        return false;
    }

    private function ipMatchesCidr(string $ip, string $cidr): bool
    {
        [$subnet, $prefixLen] = explode('/', $cidr, 2);
        $prefixLen = (int)$prefixLen;

        $ipBin = inet_pton($ip);
        $subnetBin = inet_pton($subnet);

        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $byteLen = strlen($ipBin);
        $fullBytes = intdiv($prefixLen, 8);
        $remainder = $prefixLen % 8;

        $mask = str_repeat("\xff", $fullBytes);
        if ($remainder > 0) {
            $mask .= chr(0xff & (0xff << (8 - $remainder)));
        }
        $mask = str_pad($mask, $byteLen, "\x00");

        return ($ipBin & $mask) === ($subnetBin & $mask);
    }
}
