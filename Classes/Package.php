<?php

declare(strict_types=1);

namespace KaufmannDigital\MCP;

use KaufmannDigital\MCP\Log\ThrowableStorageMasking;
use Neos\Flow\Core\Booting\Sequence;
use Neos\Flow\Core\Booting\Step;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Package\Package as BasePackage;

class Package extends BasePackage
{
    public function boot(Bootstrap $bootstrap)
    {
        $dispatcher = $bootstrap->getSignalSlotDispatcher();

        // Replace the request information / backtrace renderers of the throwable storage right after it has been
        // created, so secrets (X-Api-Token, Authorization, ...) never end up in Data/Logs/Exceptions — neither for
        // errors caught in McpHandler nor for uncaught exceptions handled by Flow's exception handler.
        $dispatcher->connect(Sequence::class, 'afterInvokeStep', function (Step $step) use ($bootstrap) {
            if ($step->getIdentifier() === 'neos.flow:systemlogger') {
                ThrowableStorageMasking::apply($bootstrap);
            }
        });
    }
}
