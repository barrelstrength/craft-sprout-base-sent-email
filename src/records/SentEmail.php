<?php
/**
 * @link      https://sprout.barrelstrengthdesign.com
 * @copyright Copyright (c) Barrel Strength Design LLC
 * @license   https://craftcms.github.io/license
 */

namespace barrelstrength\sproutbasesentemail\records;

use craft\base\Element;
use craft\db\ActiveRecord;
use yii\db\ActiveQueryInterface;

/**
 * Class SentEmail
 *
 * @property                              $id           int
 * @property                              $title        string
 * @property                              $emailSubject string
 * @property                              $fromEmail    string
 * @property                              $fromName     string
 * @property                              $toEmail      string
 * @property                              $body         string
 * @property                              $htmlBody     string
 * @property                              $info         string
 * @property                              $status       string
 * @property                              $dateCreated  DateTime
 * @property ActiveQueryInterface         $element
 * @property                              $dateUpdated  DateTime
 */
class SentEmail extends ActiveRecord
{
    /**
     * @return string
     */
    public static function tableName(): string
    {
        return '{{%sproutemail_sentemail}}';
    }

    /**
     * Returns the entry’s element.
     *
     * @return ActiveQueryInterface The relational query object.
     */
    public function getElement(): ActiveQueryInterface
    {
        return $this->hasOne(Element::class, ['id' => 'id']);
    }
}
