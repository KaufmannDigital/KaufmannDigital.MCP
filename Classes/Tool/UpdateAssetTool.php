<?php

declare(strict_types=1);

namespace KaufmannDigital\MCP\Tool;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Media\Domain\Model\Asset;
use Neos\Media\Domain\Model\Tag;
use Neos\Media\Domain\Repository\AssetRepository;
use Neos\Media\Domain\Repository\TagRepository;

/**
 * @Flow\Scope("singleton")
 */
class UpdateAssetTool implements ToolInterface
{
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
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    public function getDefinition(): array
    {
        return [
            'name' => 'update_asset',
            'description' => 'Update metadata fields of an existing asset in the Neos media library, identified by its asset identifier. Only the provided fields are changed.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'identifier' => ['type' => 'string', 'description' => 'The asset identifier (UUID) of the asset to update'],
                    'title' => ['type' => 'string', 'description' => 'Title/label for the asset (optional)'],
                    'caption' => ['type' => 'string', 'description' => 'Caption/description text for the asset (optional)'],
                    'copyrightNotice' => ['type' => 'string', 'description' => 'Copyright notice for the asset, e.g. the photographer name (optional)'],
                    'tags' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Tag labels to assign to the asset (created if they do not exist). Added to the existing tags unless replaceTags is true (optional)',
                    ],
                    'replaceTags' => ['type' => 'boolean', 'description' => 'If true, the given tags replace all existing tags instead of being added (default: false)'],
                ],
                'required' => ['identifier'],
            ],
        ];
    }

    public function execute(array $args): array
    {
        $identifier = $args['identifier'] ?? null;
        if (empty($identifier)) {
            return [['type' => 'text', 'text' => 'Error: identifier is required']];
        }

        $title = $args['title'] ?? null;
        $caption = $args['caption'] ?? null;
        $copyrightNotice = $args['copyrightNotice'] ?? null;
        $tagLabels = $args['tags'] ?? null;
        $replaceTags = $args['replaceTags'] ?? false;

        /** @var \Neos\Flow\Security\Context $securityContext */
        $securityContext = \Neos\Flow\Core\Bootstrap::$staticObjectManager->get(\Neos\Flow\Security\Context::class);

        $asset = null;
        $error = null;

        $securityContext->withoutAuthorizationChecks(function () use ($identifier, $title, $caption, $copyrightNotice, $tagLabels, $replaceTags, &$asset, &$error) {
            /** @var Asset|null $asset */
            $asset = $this->assetRepository->findByIdentifier($identifier);
            if ($asset === null) {
                $error = 'Asset not found: ' . $identifier;
                return;
            }

            if ($title !== null) {
                $asset->setTitle($title);
            }

            if ($caption !== null) {
                $asset->setCaption($caption);
            }

            if ($copyrightNotice !== null) {
                $asset->setCopyrightNotice($copyrightNotice);
            }

            if (is_array($tagLabels)) {
                if ($replaceTags) {
                    $asset->setTags(new \Doctrine\Common\Collections\ArrayCollection());
                }
                foreach ($tagLabels as $tagLabel) {
                    if ($tagLabel === '') {
                        continue;
                    }
                    $tag = $this->tagRepository->findOneByLabel($tagLabel);
                    if ($tag === null) {
                        $tag = new Tag($tagLabel);
                        $this->tagRepository->add($tag);
                    }
                    $asset->addTag($tag);
                }
            }

            $this->assetRepository->update($asset);
            $this->persistenceManager->persistAll();
        });

        if ($error !== null) {
            return [['type' => 'text', 'text' => 'Error: ' . $error]];
        }

        $tags = [];
        foreach ($asset->getTags() as $tag) {
            $tags[] = $tag->getLabel();
        }

        return [['type' => 'text', 'text' => json_encode([
            'identifier' => $this->persistenceManager->getIdentifierByObject($asset),
            'title' => $asset->getTitle(),
            'caption' => $asset->getCaption(),
            'copyrightNotice' => $asset->getCopyrightNotice(),
            'filename' => $asset->getResource()->getFilename(),
            'mediaType' => $asset->getResource()->getMediaType(),
            'tags' => $tags,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)]];
    }
}
