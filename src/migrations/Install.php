<?php

namespace markhuot\craftai\migrations;

use craft\db\Migration;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTable('{{%craftai_messages}}', [
            'id' => $this->primaryKey(),
            'sessionId' => $this->string(36)->notNull(),
            'role' => $this->string(20)->notNull(),
            // MEDIUMTEXT (16MB) — tool outputs (e.g. `get_preview` returning
            // full HTML of a CP edit page) can exceed the 64KB TEXT cap.
            'content' => $this->mediumText()->notNull(),
            'rawResponse' => $this->mediumText()->null(),
            'assetIds' => $this->text()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(
            'idx_craftai_messages_sessionId',
            '{{%craftai_messages}}',
            ['sessionId'],
        );

        $this->createTable('{{%craftai_sessions}}', [
            'id' => $this->string(36)->notNull(),
            'active' => $this->boolean()->notNull()->defaultValue(false),
            'stopRequested' => $this->boolean()->notNull()->defaultValue(false),
            'title' => $this->string()->null(),
            'userId' => $this->integer()->null(),
            'toolMode' => $this->string(16)->notNull()->defaultValue('full'),
            // JSON-encoded list<string> of tool names; only set when toolMode = 'custom'.
            'enabledTools' => $this->text()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
            'PRIMARY KEY([[id]])',
        ]);

        $this->createIndex(
            'idx_craftai_sessions_userId',
            '{{%craftai_sessions}}',
            ['userId'],
        );

        $this->addForeignKey(
            'fk_craftai_sessions_userId',
            '{{%craftai_sessions}}',
            ['userId'],
            '{{%users}}',
            ['id'],
            'SET NULL',
            'CASCADE',
        );

        $this->createTable('{{%craftai_preview_requests}}', [
            'id' => $this->primaryKey(),
            'sessionId' => $this->string(36)->notNull(),
            'toolUseId' => $this->string(64)->null(),
            'type' => $this->string(16)->notNull(),
            'input' => $this->text()->notNull(),
            'status' => $this->string(16)->notNull()->defaultValue('pending'),
            'result' => $this->mediumText()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(
            'idx_craftai_preview_requests_session_status',
            '{{%craftai_preview_requests}}',
            ['sessionId', 'status'],
        );

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%craftai_messages}}');
        $this->dropTableIfExists('{{%craftai_sessions}}');
        $this->dropTableIfExists('{{%craftai_preview_requests}}');

        return true;
    }
}
