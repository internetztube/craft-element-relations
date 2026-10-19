<?php

namespace internetztube\elementRelations\models;

use craft\base\Chippable;
use craft\base\CpEditable;
use craft\base\Iconic;
use craft\base\Model;

/**
 * A usage of an element that is not another element: a plugin's global or per-section settings
 * (e.g. SEOmatic's global SEO image). Rendered with Craft's own chip component.
 */
class GlobalUsage extends Model implements Chippable, Iconic, CpEditable
{
    /** Plugin handle that owns the usage, e.g. `seomatic`. */
    public string $provider;
    /** Unique within the provider, e.g. the settings record id. */
    public string|int $key;
    public string $label;
    public ?string $url = null;
    /** A Craft icon name (`gear`, `globe`, ...) or an SVG path/alias. */
    public ?string $icon = null;

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
