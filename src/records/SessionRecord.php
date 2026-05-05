<?php

namespace markhuot\craftai\records;

use craft\db\ActiveRecord;

/**
 * @property string $id UUID identifying the session
 * @property bool $active Whether an agent loop is currently running
 * @property bool $stopRequested User asked the running loop to halt at its next safe checkpoint
 * @property string|null $title Short summary of the user's first question
 * @property int|null $userId Craft user that initiated the session
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class SessionRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%craftai_sessions}}';
    }
}
