<?php

namespace markhuot\craftai\migrations;

use craft\db\Migration;

/**
 * Comments left on entries (or drafts) by agents during a review session.
 *
 * Each row pins a piece of feedback to a specific element + optional field
 * handle so the CP entry edit screen can render indicators next to the
 * fields the agent flagged. The user can reply via the same chat session
 * — replies thread back as user turns — and either side (user or agent)
 * can mark the comment resolved. Resolving on the agent side is itself a
 * tool call, so the conversation transcript captures the full lifecycle.
 *
 * `elementId` holds whichever id the agent supplied (a canonical entry id
 * or a draftId), and `isDraft` disambiguates. Both forms can collide in
 * the same integer namespace, so the boolean is load-bearing — the entry
 * edit controller filters comments by (elementId, isDraft) tuples
 * matching the element currently being viewed.
 */
class m260515_000001_create_comments_table extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->tableExists('{{%craftai_comments}}')) {
            return true;
        }

        $this->createTable('{{%craftai_comments}}', [
            'id' => $this->primaryKey(),
            'sessionId' => $this->string(36)->notNull(),
            'elementId' => $this->integer()->notNull(),
            'isDraft' => $this->boolean()->notNull()->defaultValue(false),
            // Null = a top-level note about the element as a whole. Strings
            // are field handles as registered in Craft (e.g. `bodyContent`).
            'fieldHandle' => $this->string()->null(),
            // Dot-path for matrix / nested-element scoping, e.g.
            // "matrixField/12345/headline". Optional — top-level field
            // comments leave this null.
            'blockPath' => $this->string()->null(),
            'body' => $this->text()->notNull(),
            'status' => $this->string(16)->notNull()->defaultValue('open'),
            'resolvedAt' => $this->dateTime()->null(),
            // 'user' or 'agent'. Tracks who closed the comment so the UI
            // can render "resolved by you" vs. "resolved by agent" without
            // joining against the message transcript.
            'resolvedBy' => $this->string(16)->null(),
            // Optional pointer back to the assistant turn whose tool_use
            // created this comment, so the UI can scroll to it in the chat
            // transcript when the user clicks through from the comment popover.
            'authorMessageId' => $this->integer()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(
            'idx_craftai_comments_session',
            '{{%craftai_comments}}',
            ['sessionId'],
        );

        $this->createIndex(
            'idx_craftai_comments_element',
            '{{%craftai_comments}}',
            ['elementId', 'isDraft', 'status'],
        );

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%craftai_comments}}');

        return true;
    }
}
