<?php

namespace markhuot\craftai\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $sessionId
 * @property string $role
 * @property string $content JSON-encoded message content blocks
 * @property string|null $rawResponse JSON-encoded full provider payload (assistant turns only)
 * @property string|null $assetIds JSON-encoded list of Craft asset IDs the user attached to this message
 * @property int|null $inputTokens Prompt tokens reported by the provider for the request that produced this assistant message
 * @property int|null $outputTokens Completion tokens reported by the provider for this assistant message
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
