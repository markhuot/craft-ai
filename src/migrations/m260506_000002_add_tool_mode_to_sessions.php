<?php

namespace markhuot\craftai\migrations;

use craft\db\Migration;

class m260506_000002_add_tool_mode_to_sessions extends Migration
{
    public function safeUp(): bool
    {
        if (! $this->db->columnExists('{{%craftai_sessions}}', 'toolMode')) {
            $this->addColumn(
                '{{%craftai_sessions}}',
                'toolMode',
                $this->string(16)->notNull()->defaultValue('full'),
            );
        }

        if (! $this->db->columnExists('{{%craftai_sessions}}', 'enabledTools')) {
            // JSON-encoded list<string> of tool names. Only meaningful when
            // toolMode = 'custom'; null in every other mode.
            $this->addColumn(
                '{{%craftai_sessions}}',
                'enabledTools',
                $this->text()->null(),
            );
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists('{{%craftai_sessions}}', 'enabledTools')) {
            $this->dropColumn('{{%craftai_sessions}}', 'enabledTools');
        }

        if ($this->db->columnExists('{{%craftai_sessions}}', 'toolMode')) {
            $this->dropColumn('{{%craftai_sessions}}', 'toolMode');
        }

        return true;
    }
}
