<?php
/** Hyper (verbb/hyper): a multi-link field with an Entry link to Page C, an Asset link to the test asset and a URL link. */

use verbb\hyper\fields\HyperField;
use verbb\hyper\links\Asset as AssetLink;
use verbb\hyper\links\Entry as EntryLink;
use verbb\hyper\links\Url as UrlLink;

return function (array $ctx): void {
    $field = Craft::$app->getFields()->getFieldByHandle('hyperLink') ?? new HyperField([
        'name' => 'Hyper Link',
        'handle' => 'hyperLink',
        'multipleLinks' => true,
        'defaultLinkType' => 'default-verbb-hyper-links-entry',
    ]);
    // new, or seeded before the URL link (not getLinkTypes(): Hyper keeps what it returned, setLinkTypes() would not count)
    if (count((array)(Craft::$app->getProjectConfig()->get("fields.$field->uid.settings.linkTypes") ?? [])) < 3) {
        // A fresh HyperField has no link types; the settings UI seeds one instance per registered class.
        $field->setLinkTypes([
            ['type' => EntryLink::class, 'handle' => 'default-verbb-hyper-links-entry', 'label' => 'Entry', 'enabled' => true],
            ['type' => AssetLink::class, 'handle' => 'default-verbb-hyper-links-asset', 'label' => 'Asset', 'enabled' => true],
            ['type' => UrlLink::class, 'handle' => 'default-verbb-hyper-links-url', 'label' => 'URL', 'enabled' => true],
        ]);
        Craft::$app->getFields()->saveField($field) or fail($field);
    }
    addFieldToLayout($field, $ctx['entryType']);

    ensureEntry('Page via Hyper', ['hyperLink' => [
        ['handle' => 'default-verbb-hyper-links-entry', 'linkValue' => $ctx['target']->id],
        ['handle' => 'default-verbb-hyper-links-asset', 'linkValue' => $ctx['asset']->id],
        ['handle' => 'default-verbb-hyper-links-url', 'linkValue' => 'https://example.test/'], // relates to nothing
    ]]);
};
