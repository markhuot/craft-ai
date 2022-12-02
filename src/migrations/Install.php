<?php

namespace markhuot\craftai\migrations;

use craft\db\Migration;
use markhuot\craftai\db\Table;

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

        return true;
    }

    function safeDown()
    {
        $this->dropTableIfExists(Table::BACKENDS);

        return true;
    }
}
