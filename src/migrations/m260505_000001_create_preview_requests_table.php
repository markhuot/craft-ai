<?php

namespace markhuot\craftai\migrations;

use craft\db\Migration;

/**
 * Stores out-of-band requests from blocking tools (OpenPreview, GetPreview) to
 * the CP front-end. The agent loop writes a row, polls it for a status flip,
 * and the front-end POSTs back through {@see \markhuot\craftai\controllers\PreviewController}
 * to resolve it. Rows are short-lived — once status is `completed` or
 * `errored` the tool reads `result` and the row is no longer load-bearing.
 */
class m260505_000001_create_preview_requests_table extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->tableExists('{{%craftai_preview_requests}}')) {
            return true;
        }

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
        $this->dropTableIfExists('{{%craftai_preview_requests}}');

        return true;
    }
}
