<?php

namespace internetztube\elementRelations\models;

use craft\base\Model;
use craft\validators\DateTimeValidator;
use DateTime;

class ElementRelationsModel extends Model
{
    /**
     * @var int|null ID
     */
    public $id;

    /**
     * @var int|null Element ID
     */
    public $elementId = 0;

    /**
     * @var int|null Site ID
     */
    public $siteId = 0;

    /**
     * @var string Relations ids
     */
    public $relations;

    /**
     * @var string Markup
     */
    public $markup;

    /**
     * @var DateTime|null Date created
     */
    public $dateCreated;

    /**
     * @var DateTime|null Date updated
     */
    public $dateUpdated;

    public function __toString()
    {
        return (string)$this->markup;
    }

    public function rules()
    {
        $rules = parent::rules();
        $rules[] = [['entryId', 'siteId'], 'number'];
        $rules[] = [['dateCreated', 'dateUpdated'], DateTimeValidator::class];

        return $rules;
    }

    public function getMarkup(): string
    {
        return $this->markup;
    }
}
