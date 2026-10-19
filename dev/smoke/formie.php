<?php

use craft\elements\Entry;
use verbb\formie\elements\Form;
use verbb\formie\elements\Submission;

return function (array $ctx): void {
    $form = Form::find()->handle('contact')->one();
    check($form && $form->redirectEntryId === $ctx['target']->id, 'form stores redirectEntryId');
    checkRelation($ctx['target'], 'Contact', 'Formie form redirect entry');
    $default = Entry::find()->title('Formie Default Target')->one();
    checkRelation($default, 'Contact', 'Formie Entries field default value');
    checkRelation($ctx['asset'], 'Contact', 'Formie notification asset attachment');

    $submission = Submission::find()->formId($form->id)->title('via Formie')->one();
    check($submission && $submission->getFieldValue('entries')->ids() === [$ctx['target']->id], 'submission stores the Entries value');
    checkRelation($ctx['target'], 'via Formie', 'Formie submission Entries field');

    checkRelation($form, 'Page via Formie', 'Formie "Forms" Craft field (generic relation field)');

    // Replace: forms and submissions are listed under their own title, so the generic round trip fits.
    replaceRoundTrip($form, $ctx['target'], 'Formie redirect entry', fn(Form $f, $t) => $f->redirectEntryId === $t->id);
    replaceRoundTrip($form, $default, 'Formie field default value', fn(Form $f, $t) => (int)($f->getFieldByHandle('entries')->defaultValue[0]['id'] ?? 0) === $t->id);
    replaceRoundTrip($form, $ctx['asset'], 'Formie notification attachment', fn(Form $f, $t) => (int)($f->getNotifications()[0]->attachAssets[0]['id'] ?? 0) === $t->id);
    replaceRoundTrip($submission, $ctx['target'], 'Formie submission value', fn(Submission $s, $t) => array_map('intval', $s->getFieldValue('entries')->ids()) === [$t->id]);
};
