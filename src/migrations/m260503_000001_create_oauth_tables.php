<?php

namespace markhuot\craftai\migrations;

use craft\db\Migration;

class m260503_000001_create_oauth_tables extends Migration
{
    public function safeUp(): bool
    {
        $this->createTable('{{%craftai_oauth_clients}}', [
            'id' => $this->primaryKey(),
            'clientId' => $this->string(64)->notNull(),
            'clientSecretHash' => $this->string(255)->null(),
            'clientName' => $this->string(255)->notNull(),
            'redirectUris' => $this->text()->notNull(),
            'grantTypes' => $this->text()->notNull(),
            'tokenEndpointAuthMethod' => $this->string(32)->notNull()->defaultValue('none'),
            'scope' => $this->string(255)->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
        $this->createIndex('idx_craftai_oauth_clients_clientId', '{{%craftai_oauth_clients}}', ['clientId'], true);

        $this->createTable('{{%craftai_oauth_auth_codes}}', [
            'id' => $this->primaryKey(),
            'code' => $this->string(128)->notNull(),
            'clientId' => $this->string(64)->notNull(),
            'userId' => $this->integer()->notNull(),
            'redirectUri' => $this->string(1024)->notNull(),
            'scope' => $this->string(255)->null(),
            'codeChallenge' => $this->string(255)->notNull(),
            'codeChallengeMethod' => $this->string(16)->notNull()->defaultValue('S256'),
            'expiresAt' => $this->dateTime()->notNull(),
            'consumed' => $this->boolean()->notNull()->defaultValue(false),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
        $this->createIndex('idx_craftai_oauth_auth_codes_code', '{{%craftai_oauth_auth_codes}}', ['code'], true);

        $this->createTable('{{%craftai_oauth_tokens}}', [
            'id' => $this->primaryKey(),
            'accessToken' => $this->string(128)->notNull(),
            'refreshToken' => $this->string(128)->null(),
            'clientId' => $this->string(64)->notNull(),
            'userId' => $this->integer()->notNull(),
            'scope' => $this->string(255)->null(),
            'accessExpiresAt' => $this->dateTime()->notNull(),
            'refreshExpiresAt' => $this->dateTime()->null(),
            'revoked' => $this->boolean()->notNull()->defaultValue(false),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
        $this->createIndex('idx_craftai_oauth_tokens_accessToken', '{{%craftai_oauth_tokens}}', ['accessToken'], true);
        $this->createIndex('idx_craftai_oauth_tokens_refreshToken', '{{%craftai_oauth_tokens}}', ['refreshToken'], false);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%craftai_oauth_tokens}}');
        $this->dropTableIfExists('{{%craftai_oauth_auth_codes}}');
        $this->dropTableIfExists('{{%craftai_oauth_clients}}');

        return true;
    }
}
