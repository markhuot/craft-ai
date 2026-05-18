<?php

namespace markhuot\craftai\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $sessionId
 * @property int $elementId
 * @property bool $isDraft
 * @property string|null $fieldHandle
 * @property string|null $blockPath
 * @property string $body
 * @property string $status
 * @property string|null $resolvedAt
 * @property string|null $resolvedBy
 * @property int|null $authorMessageId
 * @property string|null $threadSessionId Set on first user interaction — points at the forked session that carries the comment's back-and-forth
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class CommentRecord extends ActiveRecord
{
    public const STATUS_OPEN = 'open';

    public const STATUS_RESOLVED = 'resolved';

    public const RESOLVED_BY_USER = 'user';

    public const RESOLVED_BY_AGENT = 'agent';

    public static function tableName(): string
    {
        return '{{%craftai_comments}}';
    }
}
