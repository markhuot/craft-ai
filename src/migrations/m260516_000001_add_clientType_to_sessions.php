<?php

namespace markhuot\craftai\migrations;

use craft\db\Migration;

/**
 * Tag each session with the surface it was created from (CP chat, widget,
 * MCP, or the CodeComponent field's prompt tab) so the agent loop can
 * filter the registered tools by their declared `ALLOWED_CLIENTS` list
 * when assembling the toolset for a turn.
 *
 * Default is `cp` because every existing session was created from the
 * control-panel chat — that was the only surface that minted sessions
 * before this column existed.
 */
class m260516_000001_add_clientType_to_sessions extends Migration
{
    public function safeUp(): bool
    {
        if (! $this->db->columnExists('{{%craftai_sessions}}', 'clientType')) {
            $this->addColumn(
                '{{%craftai_sessions}}',
                'clientType',
                $this->string(32)->notNull()->defaultValue('cp'),
            );
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists('{{%craftai_sessions}}', 'clientType')) {
            $this->dropColumn('{{%craftai_sessions}}', 'clientType');
        }

        return true;
    }
}
