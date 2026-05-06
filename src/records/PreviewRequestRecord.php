<?php

namespace markhuot\craftai\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $sessionId
 * @property string|null $toolUseId Anthropic tool_use id this request belongs to (for correlation)
 * @property string $type 'open' for OpenPreview, 'get' for GetPreview
 * @property string $input JSON-encoded input payload (e.g. {"url": "..."} or {"fullHtml": true})
 * @property string $status 'pending' | 'completed' | 'errored'
 * @property string|null $result JSON-encoded payload returned from the front-end
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class PreviewRequestRecord extends ActiveRecord
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_ERRORED = 'errored';

    public const TYPE_OPEN = 'open';

    public const TYPE_GET = 'get';

    public static function tableName(): string
    {
        return '{{%craftai_preview_requests}}';
    }
}
