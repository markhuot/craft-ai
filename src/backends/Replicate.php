<?php

namespace markhuot\craftai\backends;

use craft\helpers\App;
use markhuot\craftai\features\Completion;
use markhuot\craftai\models\Response;
use markhuot\craftai\validators\Json as JsonValidator;

/**
 * @property array{enabledFeatures: string[], baseUrl: string, apiKey: string, textGenerationModelVersion: ?string } $settings
 */
class Replicate extends \markhuot\craftai\models\Backend implements Completion
{
    use ReplicateCompletion;

    protected array $defaultValues = [
        'type' => self::class,
        'name' => 'Replicate',
        'settings' => ['baseUrl' => 'https://api.replicate.com/v1/predictions'],
    ];

    public function getTextGenerationModelVersion(): string
    {
        return ! empty($this->settings['textGenerationModelVersion']) ? $this->settings['textGenerationModelVersion'] : 'e951f18578850b652510200860fc4ea62b3b16fac280f83ff32282f87bbd2e48';
    }

    /**
     * @return array<mixed>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            ['settings', 'required'],
            ['settings', JsonValidator::class, 'rules' => [
                [['baseUrl', 'apiKey'], 'required'],
            ]],
        ]);
    }

    /**
     * @return string[]
     */
    public function getClientHeaders(): array
    {
        return [
            'Authorization' => 'Token '.App::parseEnv($this->settings['apiKey']),
        ];
    }

    public function finish(Response $response): void
    {
        if (!$response->pending) {
            return;
        }

        $data = $this->get('/v1/predictions/' . $response->remote_id);

        if ($data['status'] !== 'succeeded') {
            return;
        }

        $response->final_payload = $data;
        $response->pending = false;
        $response->save();
    }
}
