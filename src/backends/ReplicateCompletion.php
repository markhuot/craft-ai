<?php

namespace markhuot\craftai\backends;

use markhuot\craftai\models\PendingCall;
use markhuot\craftai\models\TextCompletionResponse;

trait ReplicateCompletion
{
    public function completeText(string $text): TextCompletionResponse
    {
        /** @var array{id: string} $response */
        $response = $this->post('', [
            'version' => $this->getTextGenerationModelVersion(),
            'input' => ['prompt' => strip_tags($text)],
        ]);

        $model = new TextCompletionResponse;
        $model->backend_id = $this->id;
        $model->pending = true;
        $model->remote_id = $response['id'];
        $model->pending_payload = $response;
        $model->text = null;
        $model->save();

        return $model;
    }

    /**
     * @return array<mixed>
     */
    public function completeTextFake(string $text): array
    {
        return [
            'id' => '4ymkeptbqznryi5e5hndubt22u',
            'version' => 'e951f18578850b652510200860fc4ea62b3b16fac280f83ff32282f87bbd2e48',
            'input' => [
                'prompt' => 'User: Can you write a poem about open source machine learning? Let\'s make it in the style of E. E. Cummings. Assistant:',
            ],
            'logs' => '',
            'error' => null,
            'status' => 'starting',
            'created_at' => '2023-07-19T20:27:56.68815715Z',
            'urls' => [
                'cancel' => 'https://api.replicate.com/v1/predictions/4ymkeptbqznryi5e5hndubt22u/cancel',
                'get' => 'https://api.replicate.com/v1/predictions/4ymkeptbqznryi5e5hndubt22u',
            ],
        ];
    }
}
