<?php

namespace internetztube\elementRelations\models;

use craft\base\Model;
use craft\validators\DateTimeValidator;

/**
 * ElementRelationsModel
 */
class ElementRelationsModel extends Model
{
    // Public Properties
    // =========================================================================

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
     * @var string Result Html
     */
    public $resultHtml;

    /**
     * @var \DateTime|null Date created
     */
    public $dateCreated;

    /**
     * @var \DateTime|null Date updated
     */
    public $dateUpdated;

    // Public Methods
    // =========================================================================

    /**
     * Define what is returned when model is converted to string
     *
     * @return string
     */
    public function __toString()
    {
        return (string)$this->resultHtml;
    }

    public function rules()
    {
        $rules = parent::rules();
        $rules[] = [['entryId', 'siteId'], 'number'];
        $rules[] = [['dateCreated', 'dateUpdated'], DateTimeValidator::class];

        return $rules;
    }

    /**
     * @return string
     */
    public function getResultHtml(): string {
        return $this->resultHtml;
    }
}
