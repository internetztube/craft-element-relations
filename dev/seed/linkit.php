<?php
/** LinkIt (presseddigital/linkit): a field with the Entry type enabled, linking to Page C. */

use presseddigital\linkit\fields\LinkitField;
use presseddigital\linkit\models\Entry as EntryLink;

return function (array $ctx): void {
    $field = ensureField(LinkitField::class, [
        'name' => 'LinkIt Link',
        'handle' => 'linkitLink',
        'types' => [EntryLink::class => ['enabled' => true]],
    ]);
    addFieldToLayout($field, $ctx['entryType']);

    ensureEntry('Page via LinkIt', ['linkitLink' => ['type' => EntryLink::class, 'value' => (string)$ctx['target']->id]]);
};
