<?php

namespace internetztube\elementRelations\models;

use craft\base\Model;
use craft\validators\DateTimeValidator;
use DateTime;

class ElementRelationsModel extends Model
{
    /** @var int|null */
    public $id;

    /** @var int|null */
    public $elementId = 0;

    /** @var string */
    public $relations;

    /** @var DateTime|null */
    public $dateCreated;

    /** @var DateTime|null */
    public $dateUpdated;

    public function __toString(): string
    {
        return $this->relations;
    }

    public function rules(): array
    {
        $rules = parent::rules();
        $rules[] = [['entryId', 'siteId'], 'number'];
        $rules[] = [['dateCreated', 'dateUpdated'], DateTimeValidator::class];

        return $rules;
    }

    public function getRelations(): string
    {
        return $this->relations;
    }
}
