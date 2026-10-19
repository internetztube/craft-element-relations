<?php
/**
 * Formie: form "Contact" redirecting to `$ctx['target']`, with an Entries field defaulting to "Formie Default Target"
 * and a notification attaching `$ctx['asset']`; a submission "via Formie" whose Entries value is `$ctx['target']`;
 * and an entry "Page via Formie" whose Formie "Forms" (Craft) field selects the form.
 */

use verbb\formie\elements\Form;
use verbb\formie\elements\Submission;
use verbb\formie\factories\FormFactory;
use verbb\formie\fields\Forms as FormsField;
use verbb\formie\models\Notification;

return function (array $ctx): void {
    $target = $ctx['target'];
    $default = ensureEntry('Formie Default Target');

    $form = Form::find()->handle('contact')->status(null)->one();
    if (!$form) {
        $form = (new FormFactory(['title' => 'Contact', 'handle' => 'contact']))
            ->entriesField('entries', ['defaultValue' => [['id' => $default->id, 'siteId' => $default->siteId]]])
            ->completion('entry')
            ->create();
    }
    // Same shape the CP saves: the entry id + site in their own columns.
    $form->redirectEntryId = $target->id;
    $form->redirectEntrySiteId = $target->siteId;
    // Form::afterSave() saves the form's notifications and prunes the rest, so they go through the form.
    if (!$form->getNotifications()) {
        $form->setNotifications([new Notification([
            'name' => 'Admin',
            'handle' => 'admin',
            'enabled' => true,
            'subject' => 'New submission',
            'to' => 'admin@example.com',
            'attachAssets' => [['id' => $ctx['asset']->id]],
        ])]);
    }
    Craft::$app->getElements()->saveElement($form) or fail($form);

    $submission = Submission::find()->formId($form->id)->title('via Formie')->status(null)->one()
        ?? new Submission(['formId' => $form->id, 'title' => 'via Formie']);
    $submission->setForm($form);
    $submission->setFieldValue('entries', [$target->id]);
    Craft::$app->getElements()->saveElement($submission) or fail($submission);

    $formsField = ensureField(FormsField::class, ['name' => 'Formie Form', 'handle' => 'formieForm']);
    addFieldToLayout($formsField, $ctx['entryType']);
    ensureEntry('Page via Formie', ['formieForm' => [$form->id]]);
};
