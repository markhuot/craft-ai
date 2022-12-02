<?php

namespace markhuot\craftai\models;

use Craft;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
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

    /**
     * @template T
     *
     * @param class-string<T> $interface
     *
     * @return T
     */
    static function for(string $interface)
    {
        $possibilities = Backend::find()->all();

        foreach ($possibilities as $possibility) {
            $reflect = new ReflectionClass($possibility);
            if ($reflect->implementsInterface($interface)) {
                return $possibility;
            }
        }

        throw new RuntimeException('No backend found supporting [' . $interface . ']');
    }

    public function rules()
    {
        return [
            ['name', 'required'],
            ['type', 'required'],
        ];
    }

    public function getSettingsView()
    {
        $shortName = (new ReflectionClass($this))->getShortName();
        return 'ai/backends/_' . strtolower($shortName);
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

    function post($uri, array $body=[])
    {
        // $handler = new CurlHandler;
        // $tap = Middleware::tap(function (RequestInterface $request, $options) use ($handler) {
        //     dd($request->getHeaders());
        //     echo $request->getBody()->getContents();
        //     die;
        //     return $handler($request, $options);
        // });
        try {
            $response = $this->getClient()->request('POST', $uri, [
                // 'handler' => $tap($handler),
                'json' => $body,
            ]);

            return json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
        }

        catch (ClientException $e) {
            $response = json_decode($e->getResponse()->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
            throw new RuntimeException($response['error']['message'] ?? 'Unknown API error');
        }
    }
}
