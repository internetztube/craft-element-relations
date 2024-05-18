<?php

namespace internetztube\elementRelations\services;

use craft\base\ElementInterface;
use craft\base\Event;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\elements\User;
use craft\events\ModelEvent;
use internetztube\elementRelations\records\ElementRelationsRecord;

class UserPhotoRelationService implements RelationServiceInterface
{
    public const RELATION_TYPE = 'user-photo';

    public function registerEvents(): void
    {
        Event::on(User::class, User::EVENT_AFTER_SAVE, function (ModelEvent $event) {
            /** @var User $user */
            $user = $event->sender;
            if (!$user) return;
            $this->updateRelationsForUser($user);
        });
    }
    
    public function updateRelationsBySource(User $user)
    {
        ElementRelationsRecord::deleteAll([
            'type' => self::RELATION_TYPE,
            'sourceElementId' => $user->id
        ]);

        if (!$photoId = $user->photoId) {
            return;
        }

        $record = new ElementRelationsRecord([
            'type' => self::RELATION_TYPE,
            'sourceElementId' => $user->id,
            'targetElementId' => $photoId,
        ]);
        $record->save();
    }

    public function updateRelationsByTarget(Asset $asset)
    {
        ElementRelationsRecord::deleteAll([
            'type' => self::RELATION_TYPE,
            'targetElementId' => $asset->canonicalId,
        ]);

        $users = User::find()
            ->where(['photoId' => $asset->canonicalId])
            ->collect()
            ->each(function (User $user) use ($asset) {
                $record = new ElementRelationsRecord([
                    'type' => self::RELATION_TYPE,
                    'sourceElementId' => $user->id,
                    'targetElementId' => $user->photoId,
                ]);
                $record->save();
            });
    }
}