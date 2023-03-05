<?php

namespace markhuot\craftai\models;

use Craft;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use markhuot\craftai\backends\OpenAi;
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

    static function allFor(string $interface)
    {
        $found = [];
        $possibilities = Backend::find()->all();

        foreach ($possibilities as $possibility) {
            $reflect = new ReflectionClass($possibility);
            if ($reflect->implementsInterface($interface)) {
                $found[] = $possibility;
            }
        }

        return $found;
    }

    /**
     * @template T
     *
     * @param class-string<T> $interface
     *
     * @return T
     */
    static function for(string $interface, $silence=false)
    {
        $backends = static::allFor($interface);
        if (isset($backends[0])) {
            return $backends[0];
        }

        if ($silence === false) {
            throw new RuntimeException('No backend found supporting [' . $interface . ']');
        }
    }

    public function rules()
    {
        return [
            ['name', 'required'],
            ['type', 'required'],
        ];
    }

    function getTypeHandle()
    {
        return strtolower((new ReflectionClass($this))->getShortName());
    }

    public function getSettingsView()
    {
        $shortName = (new ReflectionClass($this))->getShortName();
        return 'ai/_backends/_' . strtolower($shortName);
    }

    function getClient()
    {
        return new Client([
            'base_uri' => Craft::parseEnv($this->settings['baseUrl']),
            'headers' => [
                'Authorization' => 'Bearer ' . Craft::parseEnv($this->settings['apiKey']),
            ],
        ]);
    }

    function post($uri, array $body=[], array $headers=[], ?string $rawBody=null, array $multipart=[])
    {
        // $handler = new CurlHandler;
        // $tap = Middleware::tap(function (RequestInterface $request, $options) use ($handler) {
        //     dd($request->getHeaders());
        //     echo $request->getBody()->getContents();
        //     die;
        //     return $handler($request, $options);
        // });
        try {
            $params = [
                'headers' => $headers,
            ];
            if (!empty($body)) {
                $params['json'] = $body;
            }
            if (!empty($rawBody)) {
                $params['body'] = $rawBody;
            }
            if (!empty($multipart)) {
                $params['multipart'] = $multipart;
            }
            $response = $this->getClient()->request('POST', $uri, $params);

            return json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
        }

        catch (ClientException|ServerException $e) {
            $this->handleErrorResponse($e);
        }
    }

    function handleErrorResponse(ClientException|ServerException $e)
    {
        throw $e;
    }

    static function factory()
    {
        $shortName = (new ReflectionClass(static::class))->getShortName();
        $fqcn = 'markhuot\\craftai\\tests\\factories\\' . $shortName;

        return $fqcn::factory();
    }
}
