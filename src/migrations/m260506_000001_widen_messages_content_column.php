<?php

namespace markhuot\craftai\migrations;

use craft\db\Migration;

/**
 * Bump `craftai_messages.content` from TEXT (64KB) to MEDIUMTEXT (16MB).
 *
 * Tool outputs can legitimately exceed 64KB — e.g. `get_preview` reading the
 * full HTML of a Craft CP edit page can run hundreds of KB. The undersized
 * column was rejecting those writes in strict-mode MySQL with a 1406 "Data
 * too long" error, which crashed the agent loop and left orphan tool_use
 * rows behind. AgentLoop::ensureToolResults() heals already-broken sessions;
 * this migration removes the underlying capacity bottleneck.
 */
class m260506_000001_widen_messages_content_column extends Migration
{
    public function safeUp(): bool
    {
        $this->alterColumn(
            '{{%craftai_messages}}',
            'content',
            $this->mediumText()->notNull(),
        );

        return true;
    }

    public function safeDown(): bool
    {
        // Truncates any rows that exceed 64KB. Provided for completeness;
        // running this is destructive and shouldn't be necessary in practice.
        $this->alterColumn(
            '{{%craftai_messages}}',
            'content',
            $this->text()->notNull(),
        );

        return true;
    }
}
