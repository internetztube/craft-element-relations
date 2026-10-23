<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations\Models;

use CraftCms\Cms\Component\Contracts\Chippable;
use CraftCms\Cms\Component\Contracts\CpEditable;
use CraftCms\Cms\Component\Contracts\Iconic;

/**
 * A usage of an element that is not another element: a plugin's global or per-section settings
 * (e.g. SEOmatic's global SEO image). Rendered with Craft's own chip component.
 */
class GlobalUsage implements Chippable, Iconic, CpEditable
{
    /**
     * @param string $provider plugin handle that owns the usage, e.g. `seomatic`
     * @param string|int $key unique within the provider, e.g. the settings record id
     * @param string|null $icon a Craft icon name (`gear`, `globe`, ...) or an SVG path
     */
    public function __construct(
        public string $provider,
        public string|int $key,
        public string $label,
        public ?string $url = null,
        public ?string $icon = null,
    ) {}

    public static function get(string|int $id): ?static
    {
        return null;
    }

    public function getId(): string|int
    {
        return "$this->provider:$this->key";
    }

    public function getUiLabel(): string
    {
        return $this->label;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function getCpEditUrl(): ?string
    {
        return $this->url;
    }
}
