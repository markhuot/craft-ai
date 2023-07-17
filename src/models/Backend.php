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
 * @property array{enabledFeatures: string[], baseUrl: string, apiKey: string} $settings
 */
class Backend extends ActiveRecord
{
    protected static bool $faked = false;

    protected Generator $faker;

    protected Client $client;

    protected array $casts = [
        'settings' => JsonCast::class,
    ];

    /** @var array<string, mixed> */
    protected array $defaultValues = [
        'type' => self::class,
        'settings' => [],
    ];

    public static ?string $polymorphicKeyField = 'type';

    public static function tableName()
    {
        return Table::BACKENDS;
    }

    public static function fake(bool $value = true): void
    {
        static::$faked = $value;
    }

    public static function isFaked(): bool
    {
        return static::$faked;
    }

    /**
     * @return array<Backend>
     */
    public static function allFor(string $interface): array
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
    public static function for(string $interface)
    {
        /** @var T[] $backends */
        $backends = static::allFor($interface);

        if (isset($backends[0])) {
            return $backends[0];
        }

        throw new RuntimeException('No backend found supporting ['.$interface.']');
    }

    public static function can(string $interface): bool
    {
        return count(static::allFor($interface)) > 0;
    }

    public function init(): void
    {
        parent::init();

        $this->faker = Factory::create();
    }

    /**
     * @return array<array-key, string[]>
     */
    public function rules(): array
    {
        return [
            ['name', 'required'],
            ['type', 'required'],
        ];
    }

    public function getTypeHandle(): string
    {
        return strtolower((new ReflectionClass($this))->getShortName());
    }

    public function getSettingsView(): string
    {
        $shortName = (new ReflectionClass($this))->getShortName();

        return 'ai/_backends/_'.strtolower($shortName);
    }

    public function getClient(): Client
    {
        return new Client([
            'base_uri' => Craft::parseEnv($this->settings['baseUrl']),
            'headers' => [
                'Authorization' => 'Bearer '.Craft::parseEnv($this->settings['apiKey']),
            ],
        ]);
    }

    /**
     * @param  array<array-key, mixed>  $body
     * @param  array<array-key, mixed>  $headers
     * @param  array<array-key, mixed>  $multipart
     * @return array<array-key, mixed>
     */
    public function post(string $uri, array $body = [], array $headers = [], string $rawBody = null, array $multipart = []): array
    {
        try {
            if (static::$faked) {
                $backtrace = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 2)[1];
                $methodName = $backtrace['function'];
                $args = $backtrace['args'] ?? [];

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

            /** @var array<mixed> $json */
            $json = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);

            return $json;
        } catch (ClientException|ServerException $e) {
            $this->handleErrorResponse($e);
        }
    }

    public function handleErrorResponse(ClientException|ServerException $e): never
    {
        throw $e;
    }

    public static function factory(): \markhuot\craftpest\factories\Factory
    {
        $shortName = (new ReflectionClass(static::class))->getShortName();
        $fqcn = 'markhuot\\craftai\\tests\\factories\\'.$shortName;

        return $fqcn::factory();
    }
}
