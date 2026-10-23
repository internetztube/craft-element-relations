<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations\Elements;

use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Element\Element;
use CraftCms\Cms\User\Elements\User;
use Override;

use function CraftCms\Cms\t;

/**
 * Stand-in for a relating element the current control panel user may not view: the usage list shows that something
 * of this type uses the element, but no title, link, status or field values (see UsageQuery::hydrate()).
 * Never saved, never queried, not a registered element type.
 */
final class RestrictedUsage extends Element
{
    /** The real element's class, for the Type column. */
    public string $realType = '';

    public static function fromElement(ElementInterface $element): self
    {
        return new self([
            'id' => $element->id,
            'siteId' => $element->siteId,
            'title' => t('restricted-title', category: 'element-relations'),
            'realType' => $element::class,
        ]);
    }

    #[Override]
    public static function displayName(): string
    {
        return t('restricted-title', category: 'element-relations');
    }

    #[Override]
    public static function hasTitles(): bool
    {
        return true;
    }

    #[Override]
    public static function isLocalized(): bool
    {
        return true;
    }

    #[Override]
    public function canView(User $user): bool
    {
        return false;
    }

    #[Override]
    public function canSave(User $user): bool
    {
        return false;
    }

    #[Override]
    public function getCpEditUrl(): ?string
    {
        return null;
    }

    /** @return array{} */
    #[Override]
    protected static function defineTableAttributes(): array
    {
        return [];
    }
}
