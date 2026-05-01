<?php

namespace markhuot\craftai\migrations;

use craft\db\Migration;

/**
 * Stores the full provider payload alongside each assistant message so we can
 * retroactively debug provider-specific fields (e.g. DeepSeek
 * `reasoning_content`) without losing data the canonical content blocks drop.
 */
class m260501_000001_add_rawResponse_to_messages extends Migration
{
    public function safeUp(): bool
    {
        if (! $this->db->columnExists('{{%craftai_messages}}', 'rawResponse')) {
            $this->addColumn(
                '{{%craftai_messages}}',
                'rawResponse',
                $this->mediumText()->null()->after('content'),
            );
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists('{{%craftai_messages}}', 'rawResponse')) {
            $this->dropColumn('{{%craftai_messages}}', 'rawResponse');
        }

        return true;
    }
}
