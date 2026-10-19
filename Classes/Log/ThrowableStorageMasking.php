<?php

declare(strict_types=1);

namespace KaufmannDigital\MCP\Log;

use Neos\Flow\Configuration\ConfigurationManager;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Error\Debugger;
use Neos\Flow\Http\HttpRequestHandlerInterface;
use Neos\Flow\Log\ThrowableStorageInterface;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Psr\Http\Message\RequestInterface;

/**
 * Masks sensitive request headers (e.g. X-Api-Token) and the configured MCP token in throwable dumps.
 *
 * Flow's default request information renderer (Scripts::initializeExceptionStorage()) writes all request headers
 * in plain text to the throwable dump — only "Authorization" is masked. This class installs equivalent renderers on
 * the active ThrowableStorageInterface that mask the configured headers and any occurrence of the token value.
 */
abstract class ThrowableStorageMasking
{
    public const MASK = '***';

    public static function apply(Bootstrap $bootstrap): void
    {
        $throwableStorage = $bootstrap->getEarlyInstance(ThrowableStorageInterface::class);
        $configurationManager = $bootstrap->getEarlyInstance(ConfigurationManager::class);
        if (!$throwableStorage instanceof ThrowableStorageInterface || !$configurationManager instanceof ConfigurationManager) {
            return;
        }

        $flowSettings = $configurationManager->getConfiguration(ConfigurationManager::CONFIGURATION_TYPE_SETTINGS, 'Neos.Flow');
        $mcpSettings = $configurationManager->getConfiguration(ConfigurationManager::CONFIGURATION_TYPE_SETTINGS, 'KaufmannDigital.MCP');

        $renderRequestInformation = (bool)($flowSettings['log']['throwables']['renderRequestInformation'] ?? true);
        $maskedHeaders = array_map('strtolower', array_values(array_filter((array)($mcpSettings['maskedRequestHeaders'] ?? []))));
        $secrets = self::secretsFromSettings($mcpSettings);

        $throwableStorage->setRequestInformationRenderer(static function () use ($renderRequestInformation, $maskedHeaders, $secrets) {
            // Mirrors Scripts::initializeExceptionStorage(), but with masked headers
            $output = '';
            if (!(Bootstrap::$staticObjectManager instanceof ObjectManagerInterface)) {
                return $output;
            }

            $bootstrap = Bootstrap::$staticObjectManager->get(Bootstrap::class);
            $requestHandler = $bootstrap->getActiveRequestHandler();
            if (!$requestHandler instanceof HttpRequestHandlerInterface) {
                return $output;
            }

            $request = $requestHandler->getHttpRequest();
            if ($renderRequestInformation) {
                $output .= PHP_EOL . 'HTTP REQUEST:' . PHP_EOL . ($request instanceof RequestInterface ? self::renderRequestInformation($request, $maskedHeaders) : '[request was empty]') . PHP_EOL;
            }
            $output .= PHP_EOL . 'PHP PROCESS:' . PHP_EOL . 'Inode: ' . getmyinode() . PHP_EOL . 'PID: ' . getmypid() . PHP_EOL . 'UID: ' . getmyuid() . PHP_EOL . 'GID: ' . getmygid() . PHP_EOL . 'User: ' . get_current_user() . PHP_EOL;

            return self::maskSecrets($output, $secrets);
        });

        $throwableStorage->setBacktraceRenderer(static function ($backtrace) use ($secrets) {
            // Backtraces may contain string call arguments — mask the token there, too
            return self::maskSecrets(Debugger::getBacktraceCode($backtrace, false, true), $secrets);
        });
    }

    /**
     * Like RequestInformationHelper::renderRequestInformation(), but masks the given headers (case-insensitive).
     *
     * @param string[] $maskedHeaders lower-cased header names
     */
    public static function renderRequestInformation(RequestInterface $request, array $maskedHeaders): string
    {
        $renderedHeaders = [];
        foreach (array_keys($request->getHeaders()) as $name) {
            $value = in_array(strtolower((string)$name), $maskedHeaders, true) ? self::MASK : $request->getHeaderLine((string)$name);
            $renderedHeaders[] = sprintf('%s: %s', $name, $value);
        }

        return implode(PHP_EOL, [sprintf('target: %s', $request->getRequestTarget()), implode(PHP_EOL, $renderedHeaders)]);
    }

    /**
     * @param string[] $secrets
     */
    public static function maskSecrets(string $text, array $secrets): string
    {
        return $secrets === [] ? $text : str_replace($secrets, self::MASK, $text);
    }

    /**
     * @return string[]
     */
    private static function secretsFromSettings(array $mcpSettings): array
    {
        $token = $mcpSettings['Token'] ?? null;
        // Only mask reasonably long values to avoid garbling the dump with trivial replacements
        return is_string($token) && strlen($token) >= 8 ? [$token] : [];
    }
}
