<?php

namespace markhuot\craftai\migrations;

use craft\db\Migration;

class m260504_000003_add_asset_ids_to_messages extends Migration
{
    public function safeUp(): bool
    {
        if (! $this->db->columnExists('{{%craftai_messages}}', 'assetIds')) {
            $this->addColumn(
                '{{%craftai_messages}}',
                'assetIds',
                $this->text()->null(),
            );
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists('{{%craftai_messages}}', 'assetIds')) {
            $this->dropColumn('{{%craftai_messages}}', 'assetIds');
        }

        return true;
    }
}
