<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations\Elements\Actions;

use CraftCms\Cms\Cp\RequestedSite;
use CraftCms\Cms\Element\ElementActions;
use CraftCms\Cms\Support\Facades\Sites;
use Override;

/**
 * Craft 6's own Entries, Assets and Users indexes (Vue) send every action to Craft's perform-action endpoint, except a
 * few of Craft's own that dispatch a window event in the browser instead. "Replace everywhere…" opens the wizard in the
 * browser, so it goes out as such an event (ReplaceRelations::CLIENT_EVENT, with the index's site), which
 * ReplaceRelations::registerClientEventJs() handles. Bound over Craft's service in ElementRelations::register().
 */
class ClientEventElementActions extends ElementActions
{
    #[Override]
    public function serializeActionItems(iterable $actions): array
    {
        $items = parent::serializeActionItems($actions);
        foreach ($items as $i => $item) {
            if ($item['key'] === ReplaceRelations::class) {
                $handle = request()->input('site');
                $site = (is_string($handle) ? Sites::getSiteByHandle($handle, true) : null)
                    ?? app(RequestedSite::class)->get()
                    ?? Sites::getCurrentSite();
                $items[$i]['action'] = ['type' => 'event', 'name' => ReplaceRelations::CLIENT_EVENT, 'detail' => ['siteId' => (int)$site->id]];
            }
        }
        return $items;
    }
}
