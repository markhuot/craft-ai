<?php

namespace markhuot\craftai\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $accessToken
 * @property string|null $refreshToken
 * @property string $clientId
 * @property int $userId
 * @property string|null $scope
 * @property string $accessExpiresAt
 * @property string|null $refreshExpiresAt
 * @property bool $revoked
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class OauthTokenRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%craftai_oauth_tokens}}';
    }
}
