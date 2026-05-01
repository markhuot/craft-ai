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
            'content' => $this->text()->notNull(),
            'rawResponse' => $this->mediumText()->null(),
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
            'title' => $this->string()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
            'PRIMARY KEY([[id]])',
        ]);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%craftai_messages}}');
        $this->dropTableIfExists('{{%craftai_sessions}}');

        return true;
    }
}
