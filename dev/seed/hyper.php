<?php
/** Hyper (verbb/hyper): a multi-link field with an Entry link to Page C and an Asset link to the test asset. */

use verbb\hyper\fields\HyperField;
use verbb\hyper\links\Asset as AssetLink;
use verbb\hyper\links\Entry as EntryLink;

return function (array $ctx): void {
    if (!$field = Craft::$app->getFields()->getFieldByHandle('hyperLink')) {
        $field = new HyperField([
            'name' => 'Hyper Link',
            'handle' => 'hyperLink',
            'multipleLinks' => true,
            'defaultLinkType' => 'default-verbb-hyper-links-entry',
        ]);
        // A fresh HyperField has no link types; the settings UI seeds one instance per registered class.
        $field->setLinkTypes([
            ['type' => EntryLink::class, 'handle' => 'default-verbb-hyper-links-entry', 'label' => 'Entry', 'enabled' => true],
            ['type' => AssetLink::class, 'handle' => 'default-verbb-hyper-links-asset', 'label' => 'Asset', 'enabled' => true],
        ]);
        Craft::$app->getFields()->saveField($field) or fail($field);
    }
    addFieldToLayout($field, $ctx['entryType']);

    ensureEntry('Page via Hyper', ['hyperLink' => [
        ['handle' => 'default-verbb-hyper-links-entry', 'linkValue' => $ctx['target']->id],
        ['handle' => 'default-verbb-hyper-links-asset', 'linkValue' => $ctx['asset']->id],
    ]]);
};
