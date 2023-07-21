<?php

namespace markhuot\craftai\migrations;

use craft\db\Migration;
use craft\db\Table as CraftTables;
use markhuot\craftai\db\Table;

class Install extends Migration
{
    public function safeUp()
    {
        $this->createTable(Table::BACKENDS, [
            'id' => $this->primaryKey(),
            'type' => $this->string()->notNull(),
            'name' => $this->string()->notNull(),
            'settings' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->addColumn(CraftTables::ASSETS, 'caption', 'varchar(512)');

        $this->createTable(Table::RESPONSES, [
            'id' => $this->primaryKey(),
            'backend_id' => $this->integer()->notNull(),
            'type' => $this->string(),
            'pending' => $this->boolean()->defaultValue(false),
            'remote_id' => $this->string(),
            'pending_payload' => $this->text()->notNull(),
            'final_payload' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        return true;
    }

    public function safeDown()
    {
        $this->dropTableIfExists(Table::BACKENDS);
        $this->dropTableIfExists(Table::RESPONSES);
        $this->dropColumn(CraftTables::ASSETS, 'caption');

        return true;
    }
}
