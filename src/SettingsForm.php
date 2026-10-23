<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations;

use CraftCms\Cms\Form\Controls\Lightswitch;
use CraftCms\Cms\Form\Controls\Number;
use CraftCms\Cms\Form\Enums\ControlMode;
use CraftCms\Cms\Form\Form;
use CraftCms\Cms\Form\Nodes\Callout;
use CraftCms\Cms\Form\Nodes\Field;

use function CraftCms\Cms\t;

/**
 * The plugin's settings page. Settings set in `config/craft/element-relations.php` win; they are shown read-only with a
 * hint, like Craft does for general config.
 */
final class SettingsForm
{
    public static function make(Settings $settings): Form
    {
        $overrides = config('craft.element-relations', []);
        $overrides = is_array($overrides) ? $overrides : [];
        $field = function (string $name, string $label, string $instructions, \CraftCms\Cms\Form\Contracts\Control $control) use ($overrides): Field {
            $node = Field::make(t($label, category: 'element-relations'), $control)->instructions(t($instructions, category: 'element-relations'));
            if (array_key_exists($name, $overrides)) {
                $control->mode(ControlMode::Disabled);
                $node->warning(t('settings-overridden', ['name' => $name], 'element-relations'));
            }
            return $node;
        };

        $form = Form::make([
            $field('bulkRefreshBatchSize', 'settings-batch-size-label', 'settings-batch-size-instructions', Number::make('bulkRefreshBatchSize')->min(1)->value($settings->bulkRefreshBatchSize)),
        ]);
        // behind the editions flag only: what the other edition adds, and where to get it (admins only)
        if (ElementRelations::upsells()) {
            $items = implode("\n", array_map(fn(string $key): string => '- ' . t("upsell-settings-$key", category: 'element-relations'), ['replace', 'undo', 'unused', 'protect', 'cli']));
            $form->add(Callout::make('element-relations-upsell', '**' . t('upsell-settings-heading', category: 'element-relations') . '**' . "\n\n"
                . t('upsell-settings-intro', category: 'element-relations') . "\n\n" . $items . "\n\n"
                . '[' . t('upsell-link', category: 'element-relations') . '](' . ElementRelations::upgradeUrl() . ')'));
        }
        // the in-use protection (Services\InUseGuard, the delete dialog)
        if (ElementRelations::isPro()) {
            $form->add($field('protectInUse', 'settings-protect-label', 'settings-protect-instructions', Lightswitch::make('protectInUse')->value($settings->protectInUse)));
        }
        return $form;
    }
}
