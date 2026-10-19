<?php

use craft\elements\Entry;
use verbb\formie\elements\Form;
use verbb\formie\elements\Submission;

return function (array $ctx): void {
    $form = Form::find()->handle('contact')->one();
    check($form && $form->redirectEntryId === $ctx['target']->id, 'form stores redirectEntryId');
    checkRelation($ctx['target'], 'Contact', 'Formie form redirect entry');
    checkRelation(Entry::find()->title('Formie Default Target')->one(), 'Contact', 'Formie Entries field default value');
    checkRelation($ctx['asset'], 'Contact', 'Formie notification asset attachment');

    $submission = Submission::find()->formId($form->id)->title('via Formie')->one();
    check($submission && $submission->getFieldValue('entries')->ids() === [$ctx['target']->id], 'submission stores the Entries value');
    checkRelation($ctx['target'], 'via Formie', 'Formie submission Entries field');

    checkRelation($form, 'Page via Formie', 'Formie "Forms" Craft field (generic relation field)');
};
