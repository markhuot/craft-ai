<?php

namespace markhuot\craftai\models;

use Illuminate\Support\Arr;
use markhuot\craftai\db\Model;
use markhuot\craftai\search\NullSearch;

class Settings extends Model
{
    /**
     * Whether the system should reach out to the various AI services or
     * rely on fakes.
     */
    public bool $useFakes = false;

    /**
     * The driver to use for the AI services.
     */
    public string $searchDriver = 'null';

    /**
     * @var array The configuration for the various search back-ends
     */
    public array $searchDrivers = ['null' => ['class' => NullSearch::class]];

    public function get($key)
    {
        return Arr::get($this, $key);
    }
}
