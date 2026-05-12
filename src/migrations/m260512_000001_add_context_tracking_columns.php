<?php

namespace markhuot\craftai\migrations;

use craft\db\Migration;

/**
 * Track per-message token usage so the chat UI can render a context-window
 * gauge and the agent loop can auto-compact when the conversation gets near
 * the model's limit.
 *
 * `inputTokens` / `outputTokens` populate on assistant rows from the provider's
 * usage payload. `compactionPivotId` points at the most recent summary row for
 * a session — when set, loadMessages() ignores anything with a lower id and
 * folds the summary in as a system note. The pivot is nullable because most
 * sessions never need a compaction.
 */
class m260512_000001_add_context_tracking_columns extends Migration
{
    public function safeUp(): bool
    {
        if (! $this->db->columnExists('{{%craftai_messages}}', 'inputTokens')) {
            $this->addColumn(
                '{{%craftai_messages}}',
                'inputTokens',
                $this->integer()->null(),
            );
        }

        if (! $this->db->columnExists('{{%craftai_messages}}', 'outputTokens')) {
            $this->addColumn(
                '{{%craftai_messages}}',
                'outputTokens',
                $this->integer()->null(),
            );
        }

        if (! $this->db->columnExists('{{%craftai_sessions}}', 'compactionPivotId')) {
            $this->addColumn(
                '{{%craftai_sessions}}',
                'compactionPivotId',
                $this->integer()->null(),
            );
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists('{{%craftai_sessions}}', 'compactionPivotId')) {
            $this->dropColumn('{{%craftai_sessions}}', 'compactionPivotId');
        }

        if ($this->db->columnExists('{{%craftai_messages}}', 'outputTokens')) {
            $this->dropColumn('{{%craftai_messages}}', 'outputTokens');
        }

        if ($this->db->columnExists('{{%craftai_messages}}', 'inputTokens')) {
            $this->dropColumn('{{%craftai_messages}}', 'inputTokens');
        }

        return true;
    }
}
