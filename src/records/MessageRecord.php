<?php

namespace markhuot\craftai\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $sessionId
 * @property string $role
 * @property string $content JSON-encoded message content blocks
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class MessageRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%craftai_messages}}';
    }
}
