<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations\Form;

use CraftCms\Cms\Form\ControlPayload;
use CraftCms\Cms\Form\Controls\Control;
use CraftCms\Cms\Form\FormHtmlRenderer;
use CraftCms\Cms\Support\Facades\HtmlStack;
use CraftCms\Cms\View\Enums\Position;
use CraftCms\Cms\View\HtmlFragment;
use Override;

/**
 * A field input made of control panel HTML and its scripts: the field's usage list is Craft's element index (legacy
 * JavaScript), chips and labels. Rendered by Craft's own `craft-legacy:html` component (cms-assets' cpcompat bundle,
 * loaded by ElementRelations::boot()), which runs the captured head and body HTML like a classic page. The field
 * stores nothing, so whatever the component posts back is ignored.
 */
class HtmlControl extends Control
{
    private ?HtmlFragment $fragment = null;

    /** The HTML and the scripts it registered, captured with HtmlStack::capture(). */
    public function fragment(HtmlFragment $fragment): static
    {
        $this->fragment = $fragment;
        return $this;
    }

    #[Override]
    public static function renderHtml(ControlPayload $control, mixed $value, array $attributes, FormHtmlRenderer $renderer): string
    {
        $fragment = $control->props['fragment'] ?? [];
        $fragment = is_array($fragment) ? $fragment : [];
        if (($fragment['headHtml'] ?? '') !== '') {
            HtmlStack::html((string)$fragment['headHtml'], Position::Head);
        }
        if (($fragment['bodyHtml'] ?? '') !== '') {
            HtmlStack::html((string)$fragment['bodyHtml'], Position::BodyEnd);
        }
        return (string)($fragment['html'] ?? '');
    }

    #[Override]
    public function component(): string
    {
        return 'craft-legacy:html';
    }

    /** @return array{fragment: array{html: string, headHtml: string, bodyHtml: string}, namespace: null, expandValues: false} */
    #[Override]
    public function props(mixed $value = null): array
    {
        $fragment = $this->fragment?->toArray() ?? [];
        return [
            'fragment' => [
                'html' => (string)($fragment['html'] ?? ''),
                'headHtml' => (string)($fragment['headHtml'] ?? ''),
                'bodyHtml' => (string)($fragment['bodyHtml'] ?? ''),
            ],
            'namespace' => null,
            'expandValues' => false,
        ];
    }
}
