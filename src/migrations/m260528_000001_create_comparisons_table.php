<?php

namespace markhuot\craftai\migrations;

use craft\db\Migration;

/**
 * Memoizes revision comparisons (see
 * {@see \markhuot\craftai\records\ComparisonRecord}). Each
 * (entryId, siteId, aRef, bRef) maps to one narration session + rendered
 * artifact, so reopening the same comparison reuses them instead of minting a
 * new session and re-narrating the diff. Rows cascade-delete with their
 * session; the artifact link clears if the artifact is pruned independently.
 *
 * Companion to the same table created inline by {@see Install}; both guard on
 * `tableExists` so a fresh install and an upgrade converge on one schema.
 */
class m260528_000001_create_comparisons_table extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->tableExists('{{%craftai_comparisons}}')) {
            return true;
        }

        $this->createTable('{{%craftai_comparisons}}', [
            'id' => $this->primaryKey(),
            'entryId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            // Refs are short tokens ("current", "rev:123", "draft:123"); a tight
            // cap keeps the 4-column unique index well under InnoDB's key limit.
            'aRef' => $this->string(64)->notNull(),
            'bRef' => $this->string(64)->notNull(),
            'fingerprint' => $this->string(64)->notNull(),
            'sessionId' => $this->string(36)->notNull(),
            'artifactId' => $this->integer()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(
            'idx_craftai_comparisons_lookup',
            '{{%craftai_comparisons}}',
            ['entryId', 'siteId', 'aRef', 'bRef'],
            unique: true,
        );

        $this->addForeignKey(
            'fk_craftai_comparisons_session',
            '{{%craftai_comparisons}}',
            ['sessionId'],
            '{{%craftai_sessions}}',
            ['id'],
            'CASCADE',
            'CASCADE',
        );

        $this->addForeignKey(
            'fk_craftai_comparisons_artifact',
            '{{%craftai_comparisons}}',
            ['artifactId'],
            '{{%craftai_artifacts}}',
            ['id'],
            'SET NULL',
            'CASCADE',
        );

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%craftai_comparisons}}');

        return true;
    }
}
