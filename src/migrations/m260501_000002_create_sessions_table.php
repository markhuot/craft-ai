<?php

namespace markhuot\craftai\migrations;

use craft\db\Migration;
use craft\db\Query;
use craft\helpers\Db;

/**
 * Adds a dedicated sessions table so we can track agent-loop state (active vs.
 * idle) independently from the message log.
 */
class m260501_000002_create_sessions_table extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->tableExists('{{%craftai_sessions}}')) {
            return true;
        }

        $this->createTable('{{%craftai_sessions}}', [
            'id' => $this->string(36)->notNull(),
            'active' => $this->boolean()->notNull()->defaultValue(false),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
            'PRIMARY KEY([[id]])',
        ]);

        /** @var list<array{sessionId: string, firstMessage: ?string}> $existing */
        $existing = (new Query())
            ->select(['sessionId', 'firstMessage' => 'MIN([[dateCreated]])'])
            ->from('{{%craftai_messages}}')
            ->groupBy('sessionId')
            ->all($this->db);

        $now = Db::prepareDateForDb(new \DateTime());
        foreach ($existing as $row) {
            $this->insert('{{%craftai_sessions}}', [
                'id' => $row['sessionId'],
                'active' => false,
                'dateCreated' => $row['firstMessage'] ?? $now,
                'dateUpdated' => $now,
                'uid' => \craft\helpers\StringHelper::UUID(),
            ]);
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%craftai_sessions}}');

        return true;
    }
}
