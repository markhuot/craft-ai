<?php

namespace markhuot\craftai\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $code
 * @property string $clientId
 * @property int $userId
 * @property string $redirectUri
 * @property string|null $scope
 * @property string $codeChallenge
 * @property string $codeChallengeMethod
 * @property string $expiresAt
 * @property bool $consumed
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class OauthAuthCodeRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%craftai_oauth_auth_codes}}';
    }
}
