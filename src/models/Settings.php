<?php

namespace markhuot\craftai\models;

use Illuminate\Support\Arr;
use markhuot\craftai\db\Model;

class Settings extends Model
{
    /**
     * Whether the system should reach out to the various AI services or
     * rely on fakes.
     */
    public bool $useFakes;

    /**
     * The driver to use for the AI services.
     */
    public string $searchDriver;

    /**
     * @var array The configuration for the various search back-ends
     */
    public array $searchDrivers;

    public function __construct($config = [])
    {
        $defaultConfig = require __DIR__.'/../config.php';
        foreach ($defaultConfig as $key => $value) {
            $this->{$key} = $value;
        }

        parent::__construct($config);
    }

    public function get($key)
    {
        return Arr::get($this, $key);
    }
}
