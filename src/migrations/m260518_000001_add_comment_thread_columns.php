<?php

namespace markhuot\craftai\migrations;

use craft\db\Migration;

/**
 * Add session-fork bookkeeping so each comment can sprout its own
 * conversation without polluting the parent transcript.
 *
 * `craftai_sessions.parentSessionId` + `originatingCommentId` mark a row
 * as a fork — a copy of the parent's history up to the message that left
 * the comment, plus the back-and-forth that follows. The parent stays
 * clean; the fork carries the comment-specific discussion.
 *
 * `craftai_comments.threadSessionId` is the back-reference: when the user
 * opens a comment, we look this up to decide whether to create the fork
 * (first interaction) or reuse the existing one (subsequent replies).
 */
class m260518_000001_add_comment_thread_columns extends Migration
{
    public function safeUp(): bool
    {
        if (! $this->db->columnExists('{{%craftai_sessions}}', 'parentSessionId')) {
            $this->addColumn(
                '{{%craftai_sessions}}',
                'parentSessionId',
                $this->string(36)->null(),
            );
        }

        if (! $this->db->columnExists('{{%craftai_sessions}}', 'originatingCommentId')) {
            $this->addColumn(
                '{{%craftai_sessions}}',
                'originatingCommentId',
                $this->integer()->null(),
            );
        }

        if (! $this->db->columnExists('{{%craftai_sessions}}', 'forkPivotMessageId')) {
            // The MessageRecord id (in the fork's own row space) of the last
            // copied message — i.e. the "history boundary." Anything with an
            // id higher than this in the same session is part of the
            // comment's discussion (replies + agent responses), not the
            // parent's transcript. Lets the UI compute a reply count without
            // having to diff against the parent session.
            $this->addColumn(
                '{{%craftai_sessions}}',
                'forkPivotMessageId',
                $this->integer()->null(),
            );
        }

        if (! $this->db->columnExists('{{%craftai_comments}}', 'threadSessionId')) {
            $this->addColumn(
                '{{%craftai_comments}}',
                'threadSessionId',
                $this->string(36)->null(),
            );
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists('{{%craftai_comments}}', 'threadSessionId')) {
            $this->dropColumn('{{%craftai_comments}}', 'threadSessionId');
        }

        if ($this->db->columnExists('{{%craftai_sessions}}', 'forkPivotMessageId')) {
            $this->dropColumn('{{%craftai_sessions}}', 'forkPivotMessageId');
        }

        if ($this->db->columnExists('{{%craftai_sessions}}', 'originatingCommentId')) {
            $this->dropColumn('{{%craftai_sessions}}', 'originatingCommentId');
        }

        if ($this->db->columnExists('{{%craftai_sessions}}', 'parentSessionId')) {
            $this->dropColumn('{{%craftai_sessions}}', 'parentSessionId');
        }

        return true;
    }
}
