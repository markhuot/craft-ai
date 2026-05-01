<?php

namespace markhuot\craftai\migrations;

use craft\db\Migration;

class m260501_000003_add_title_to_sessions extends Migration
{
    public function safeUp(): bool
    {
        if (! $this->db->columnExists('{{%craftai_sessions}}', 'title')) {
            $this->addColumn('{{%craftai_sessions}}', 'title', $this->string()->null());
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists('{{%craftai_sessions}}', 'title')) {
            $this->dropColumn('{{%craftai_sessions}}', 'title');
        }

        return true;
    }
}
