<?php

namespace markhuot\craftai\migrations;

use craft\db\Migration;

/**
 * Runtime state + audit log for scheduled agents (see
 * {@see \markhuot\craftai\records\ScheduledRunRecord}). Each agent keeps
 * one precomputed `pending` slot row plus a terminal row per processed
 * slot (`queued` / `missed` / `error`), so the dispatcher can detect
 * downtime windows and the settings UI can show next/last runs without
 * touching project config.
 *
 * Companion to the same table created inline by {@see Install}; both
 * guard on `tableExists` so a fresh install and an upgrade converge on
 * one schema.
 */
class m260604_000001_create_scheduled_runs_table extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->tableExists('{{%craftai_scheduled_runs}}')) {
            return true;
        }

        $this->createTable('{{%craftai_scheduled_runs}}', [
            'id' => $this->primaryKey(),
            'scheduledAgentUid' => $this->string(36)->notNull(),
            'scheduledFor' => $this->dateTime()->notNull(),
            'status' => $this->string(16)->notNull()->defaultValue('pending'),
            'sessionId' => $this->string(36)->null(),
            'detail' => $this->string()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(
            'idx_craftai_scheduled_runs_agent_status',
            '{{%craftai_scheduled_runs}}',
            ['scheduledAgentUid', 'status'],
        );

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%craftai_scheduled_runs}}');

        return true;
    }
}
