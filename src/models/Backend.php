<?php

namespace markhuot\craftai\models;

use Craft;
use Faker\Factory;
use Faker\Generator;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use markhuot\craftai\casts\Json as JsonCast;
use markhuot\craftai\db\ActiveRecord;
use markhuot\craftai\db\Table;
use ReflectionClass;
use RuntimeException;

/**
 * @property array $settings
 */
class Backend extends ActiveRecord
{
    protected static bool $faked = false;
    protected Generator $faker;

    protected Client $client;

    protected array $casts = [
        'settings' => JsonCast::class,
    ];

    protected array $defaultValues = [
        'type' => self::class,
    ];

    public static $polymorphicKeyField = 'type';

    public static function tableName()
    {
        return Table::BACKENDS;
    }

    public static function fake(bool $value = true)
    {
        static::$faked = $value;
    }

    public static function isFaked()
    {
        return static::$faked;
    }

    public static function allFor(string $interface)
    {
        $found = [];
        /** @var Backend[] $possibilities */
        $possibilities = Backend::find()->all();

        foreach ($possibilities as $possibility) {
            $reflect = new ReflectionClass($possibility);
            if ($reflect->implementsInterface($interface) && collect($possibility->settings['enabledFeatures'] ?? [])->contains($interface)) {
                $found[] = $possibility;
            }
        }

        return $found;
    }

    /**
     * @template T
     *
     * @param  class-string<T>  $interface
     * @return T
     */
    public static function for(string $interface, $silence = false)
    {
        $backends = static::allFor($interface);
        if (isset($backends[0])) {
            return $backends[0];
        }

        if ($silence === false) {
            throw new RuntimeException('No backend found supporting ['.$interface.']');
        }
    }

    public static function can(string $interface): bool
    {
        return count(static::allFor($interface)) > 0;
    }

    public function init()
    {
        parent::init();

        $this->faker = Factory::create();
    }

    public function rules()
    {
        return [
            ['name', 'required'],
            ['type', 'required'],
        ];
    }

    public function getTypeHandle()
    {
        return strtolower((new ReflectionClass($this))->getShortName());
    }

    public function getSettingsView()
    {
        $shortName = (new ReflectionClass($this))->getShortName();

        return 'ai/_backends/_'.strtolower($shortName);
    }

    public function getClient()
    {
        return new Client([
            'base_uri' => Craft::parseEnv($this->settings['baseUrl']),
            'headers' => [
                'Authorization' => 'Bearer '.Craft::parseEnv($this->settings['apiKey']),
            ],
        ]);
    }

    public function post($uri, array $body = [], array $headers = [], ?string $rawBody = null, array $multipart = [])
    {
        try {
            if (static::$faked) {
                ['function' => $methodName, 'args' => $args] = debug_backtrace(! DEBUG_BACKTRACE_PROVIDE_OBJECT, 2)[1];
                $fakeMethodName = $methodName.'Fake';
                if (method_exists($this, $fakeMethodName)) {
                    return $this->$fakeMethodName(...$args);
                }
            }

            $params = [
                'headers' => $headers,
            ];
            if (! empty($body)) {
                $params['json'] = $body;
            }
            if (! empty($rawBody)) {
                $params['body'] = $rawBody;
            }
            if (! empty($multipart)) {
                $params['multipart'] = $multipart;
            }

            $response = $this->getClient()->request('POST', $uri, $params);

            return json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
        } catch (ClientException|ServerException $e) {
            $this->handleErrorResponse($e);
        }
    }

    public function handleErrorResponse(ClientException|ServerException $e)
    {
        throw $e;
    }

    public static function factory()
    {
        $shortName = (new ReflectionClass(static::class))->getShortName();
        $fqcn = 'markhuot\\craftai\\tests\\factories\\'.$shortName;

        return $fqcn::factory();
    }
}
