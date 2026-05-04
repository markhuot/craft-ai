<?php

namespace markhuot\craftai\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $clientId
 * @property string|null $clientSecretHash
 * @property string $clientName
 * @property string $redirectUris JSON list
 * @property string $grantTypes JSON list
 * @property string $tokenEndpointAuthMethod
 * @property string|null $scope
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class OauthClientRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%craftai_oauth_clients}}';
    }
}
