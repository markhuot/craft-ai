<?php

namespace markhuot\craftai\migrations;

use craft\db\Migration;

class m260504_000002_add_stop_requested_to_sessions extends Migration
{
    public function safeUp(): bool
    {
        if (! $this->db->columnExists('{{%craftai_sessions}}', 'stopRequested')) {
            $this->addColumn(
                '{{%craftai_sessions}}',
                'stopRequested',
                $this->boolean()->notNull()->defaultValue(false),
            );
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists('{{%craftai_sessions}}', 'stopRequested')) {
            $this->dropColumn('{{%craftai_sessions}}', 'stopRequested');
        }

        return true;
    }
}
