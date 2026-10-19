<?php

declare(strict_types=1);

namespace internetztube\elementRelations\elements;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\elements\User;

/**
 * Stand-in for a relating element the current control panel user may not view: the usage list shows that something
 * of this type uses the element, but no title, link, status or field values (see UsageQuery::populate()).
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
            'title' => Craft::t('element-relations', 'restricted-title'),
            'realType' => $element::class,
        ]);
    }

    public static function displayName(): string
    {
        return Craft::t('element-relations', 'restricted-title');
    }

    public static function hasTitles(): bool
    {
        return true;
    }

    public static function isLocalized(): bool
    {
        return true;
    }

    public function canView(User $user): bool
    {
        return false;
    }

    public function canSave(User $user): bool
    {
        return false;
    }

    public function getCpEditUrl(): ?string
    {
        return null;
    }

    /** @return array{} */
    protected static function defineTableAttributes(): array
    {
        return [];
    }
}
