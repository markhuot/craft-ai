<?php

namespace markhuot\craftai\backends;

use markhuot\craftai\models\EmbeddingsResponse;

trait OpenAiGenerateEmbeddings
{
    public function generateEmbeddings(string $text): EmbeddingsResponse
    {
        /** @var array{data: array<array{embedding: array<float>}>} $response */
        $response = $this->post('embeddings', [
            'input' => $text,
            'model' => 'text-embedding-ada-002',
        ]);

        $model = new EmbeddingsResponse;
        $model->vectors = $response['data'][0]['embedding'];

        return $model;
    }

    /**
     * @return array<mixed>
     */
    public function generateEmbeddingsFake(string $text): array
    {
        $vectors = collect(range(1, 1536))->map(fn () => -0.00025898186)->toArray();

        return [
            'data' => [
                [
                    'embedding' => $vectors,
                ],
            ],
            'model' => 'text-embedding-ada-002',
            'object' => 'list',
            'usage' => [
                'prompt_tokens' => 5,
                'total_tokens' => 5,
            ],
        ];
    }
}
