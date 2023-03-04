<?php

namespace markhuot\craftai\migrations;

use craft\db\Migration;
use markhuot\craftai\db\Table;
use craft\db\Table as CraftTables;

class Install extends Migration
{
    function safeUp()
    {
        $this->createTable(Table::BACKENDS, [
            'id' => $this->primaryKey(),
            'type' => $this->string()->notNull(),
            'name' => $this->string()->notNull(),
            'settings' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->addColumn(CraftTables::ASSETS, 'caption', 'varchar(255)');

        return true;
    }

    function safeDown()
    {
        $this->dropTableIfExists(Table::BACKENDS);
        $this->dropColumn(CraftTables::ASSETS, 'caption');

        return true;
    }
}
