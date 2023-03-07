<?php

namespace markhuot\craftai\models;

use craft\base\Model;

class Settings extends Model
{
    /**
     * Whether the system should reach out to the various AI services or
     * rely on fakes.
     */
    public bool $useFakes = false;
}
