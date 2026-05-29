<?php

namespace markhuot\craftai\migrations;

use craft\db\Migration;

/**
 * Stores agent-authored HTML artifacts (e.g. a rendered revision diff). Rows
 * are owned by a session and cascade-deleted with it. The HTML is served only
 * through {@see \markhuot\craftai\controllers\ArtifactsController} under a
 * strict CSP, inside a sandboxed iframe — never inlined into the CP DOM.
 */
class m260527_000001_create_artifacts_table extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->tableExists('{{%craftai_artifacts}}')) {
            return true;
        }

        $this->createTable('{{%craftai_artifacts}}', [
            'id' => $this->primaryKey(),
            'sessionId' => $this->string(36)->notNull(),
            'entryId' => $this->integer()->null(),
            'title' => $this->string()->notNull()->defaultValue(''),
            // longText (4GB) — rich rendered diffs of large Matrix entries can
            // exceed the mediumText (16MB) ceiling used elsewhere; give headroom.
            'html' => $this->longText()->notNull(),
            'mimeType' => $this->string(64)->notNull()->defaultValue('text/html'),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(
            'idx_craftai_artifacts_session',
            '{{%craftai_artifacts}}',
            ['sessionId'],
        );

        $this->addForeignKey(
            'fk_craftai_artifacts_session',
            '{{%craftai_artifacts}}',
            ['sessionId'],
            '{{%craftai_sessions}}',
            ['id'],
            'CASCADE',
            'CASCADE',
        );

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%craftai_artifacts}}');

        return true;
    }
}
