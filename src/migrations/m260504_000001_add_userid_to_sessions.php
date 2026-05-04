<?php

namespace markhuot\craftai\migrations;

use craft\db\Migration;

class m260504_000001_add_userid_to_sessions extends Migration
{
    public function safeUp(): bool
    {
        if (! $this->db->columnExists('{{%craftai_sessions}}', 'userId')) {
            $this->addColumn('{{%craftai_sessions}}', 'userId', $this->integer()->null());
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
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists('{{%craftai_sessions}}', 'userId')) {
            try {
                $this->dropForeignKey('fk_craftai_sessions_userId', '{{%craftai_sessions}}');
            } catch (\Throwable) {
                // ignore
            }
            try {
                $this->dropIndex('idx_craftai_sessions_userId', '{{%craftai_sessions}}');
            } catch (\Throwable) {
                // ignore
            }
            $this->dropColumn('{{%craftai_sessions}}', 'userId');
        }

        return true;
    }
}
