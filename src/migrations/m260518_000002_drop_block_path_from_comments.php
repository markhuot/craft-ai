<?php

namespace markhuot\craftai\migrations;

use craft\db\Migration;

/**
 * Drop the `blockPath` column. Matrix blocks are first-class entries in
 * Craft 5, so a comment on a nested-block field simply targets the
 * block's own elementId — no separate dot-path scoping needed.
 *
 * Previously left-dangling: the column was written by `leave_comment` but
 * the CP overlay never consumed it, so the indicators were rendering on
 * the wrong field (the outer Matrix instead of the inner field).
 */
class m260518_000002_drop_block_path_from_comments extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->columnExists('{{%craftai_comments}}', 'blockPath')) {
            $this->dropColumn('{{%craftai_comments}}', 'blockPath');
        }

        return true;
    }

    public function safeDown(): bool
    {
        if (! $this->db->columnExists('{{%craftai_comments}}', 'blockPath')) {
            $this->addColumn(
                '{{%craftai_comments}}',
                'blockPath',
                $this->string()->null(),
            );
        }

        return true;
    }
}
