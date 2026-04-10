<?php

declare(strict_types=1);

namespace KaufmannDigital\MCP\Tool;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Media\Domain\Model\Tag;
use Neos\Media\Domain\Repository\AssetRepository;
use Neos\Media\Domain\Repository\TagRepository;
use Neos\Media\Domain\Strategy\AssetModelMappingStrategyInterface;

/**
 * @Flow\Scope("singleton")
 */
class UploadAssetTool implements ToolInterface
{
    /**
     * @Flow\Inject
     * @var ResourceManager
     */
    protected $resourceManager;

    /**
     * @Flow\Inject
     * @var AssetRepository
     */
    protected $assetRepository;

    /**
     * @Flow\Inject
     * @var TagRepository
     */
    protected $tagRepository;

    /**
     * @Flow\Inject
     * @var AssetModelMappingStrategyInterface
     */
    protected $assetModelMappingStrategy;

    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * @Flow\InjectConfiguration(path="localImportBasePath")
     * @var string
     */
    protected $localImportBasePath;

    public function getDefinition(): array
    {
        return [
            'name' => 'upload_asset',
            'description' => 'Import a file into the Neos media library from a URL or a local absolute path (e.g. /var/www/html/cover-images/file.jpg or /mnt/ddev_config/file.pdf). Optionally assigns a tag.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'url' => ['type' => 'string', 'description' => 'URL or absolute local path (e.g. /var/www/html/cover-images/file.jpg) of the file to import'],
                    'filename' => ['type' => 'string', 'description' => 'Original filename including extension (e.g. fm_01.pdf) — required when using a local path'],
                    'title' => ['type' => 'string', 'description' => 'Title/label for the asset in the media library (optional)'],
                    'tag' => ['type' => 'string', 'description' => 'Tag label to assign to the asset (created if it does not exist, optional)'],
                ],
                'required' => ['url'],
            ],
        ];
    }

    public function execute(array $args): array
    {
        $url = $args['url'] ?? null;
        if (empty($url)) {
            return [['type' => 'text', 'text' => 'Error: url is required']];
        }

        $title = $args['title'] ?? null;
        $filename = $args['filename'] ?? null;
        $tagLabel = $args['tag'] ?? null;

        /** @var \Neos\Flow\Security\Context $securityContext */
        $securityContext = \Neos\Flow\Core\Bootstrap::$staticObjectManager->get(\Neos\Flow\Security\Context::class);

        $asset = null;
        $error = null;

        $securityContext->withoutAuthorizationChecks(function () use ($url, $title, $filename, $tagLabel, &$asset, &$error) {
            try {
                if (str_starts_with($url, '/')) {
                    // Resolve base path for local file access restriction
                    $basePath = $this->localImportBasePath;
                    if (!str_starts_with($basePath, '/')) {
                        $basePath = FLOW_PATH_ROOT . $basePath;
                    }
                    $realBasePath = realpath($basePath);
                    if ($realBasePath === false) {
                        $error = 'Local import base path does not exist or is not accessible: ' . $basePath;
                        return;
                    }
                    $realBasePath = rtrim($realBasePath, '/') . '/';

                    // Resolve the parent directory of the requested file (handles symlinks)
                    // and append only the basename — this avoids realpath() failing on
                    // filenames with non-ASCII characters while still preventing traversal.
                    $parentDir = realpath(dirname($url));
                    if ($parentDir === false) {
                        $error = 'Directory does not exist: ' . dirname($url);
                        return;
                    }
                    $resolvedPath = rtrim($parentDir, '/') . '/' . basename($url);

                    if (!str_starts_with(rtrim($parentDir, '/') . '/', $realBasePath)) {
                        $error = 'Local file access is restricted to: ' . rtrim($realBasePath, '/');
                        return;
                    }

                    // Handle Unicode normalization differences (NFC vs NFD) in filenames
                    if (!file_exists($resolvedPath) && class_exists('Normalizer')) {
                        foreach ([\Normalizer::FORM_C, \Normalizer::FORM_D] as $form) {
                            $candidate = rtrim($parentDir, '/') . '/' . \Normalizer::normalize(basename($url), $form);
                            if (file_exists($candidate)) {
                                $resolvedPath = $candidate;
                                break;
                            }
                        }
                    }

                    $ext = pathinfo($resolvedPath, PATHINFO_EXTENSION);
                    $tempPath = sys_get_temp_dir() . '/' . uniqid('mcp_asset_') . ($ext !== '' ? '.' . $ext : '');
                    if (!copy($resolvedPath, $tempPath)) {
                        $error = 'Failed to read local file: ' . $resolvedPath;
                        return;
                    }
                    try {
                        $resource = $this->resourceManager->importResource($tempPath);
                    } finally {
                        @unlink($tempPath);
                    }
                } else {
                    $resource = $this->resourceManager->importResource($url);
                }
            } catch (\Exception $e) {
                $error = 'Failed to import resource: ' . $e->getMessage();
                return;
            }

            if ($filename !== null) {
                $resource->setFilename($filename);
            }

            $assetModelClass = $this->assetModelMappingStrategy->map($resource);
            $asset = new $assetModelClass($resource);

            if ($title !== null) {
                $asset->setTitle($title);
            }

            if ($tagLabel !== null) {
                $tag = $this->tagRepository->findOneByLabel($tagLabel);
                if ($tag === null) {
                    $tag = new Tag($tagLabel);
                    $this->tagRepository->add($tag);
                }
                $asset->addTag($tag);
            }

            $this->assetRepository->add($asset);
            $this->persistenceManager->persistAll();
        });

        if ($error !== null) {
            return [['type' => 'text', 'text' => 'Error: ' . $error]];
        }

        $identifier = $this->persistenceManager->getIdentifierByObject($asset);

        return [['type' => 'text', 'text' => json_encode([
            'identifier' => $identifier,
            'title' => $asset->getTitle(),
            'filename' => $asset->getResource()->getFilename(),
            'mediaType' => $asset->getResource()->getMediaType(),
            'tag' => $tagLabel,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)]];
    }
}
