<?php

namespace markhuot\craftai\backends;

use markhuot\craftai\models\EmbeddingsResponse;

trait OpenAiGenerateEmbeddings
{
    function generateEmbeddings(string $text): EmbeddingsResponse
    {
        $response = $this->post('embeddings', [

        ]);

        $model = new EmbeddingsResponse;
        $model->vectors = $response['data']['embedding'];

        return $model;
    }

    function generateEmbeddingsFake(string $text): array
    {
        return [
            'data' => [
                'embedding' => collect(range(1, 1024))->map(fn () => rand(-1000, 1000) / 100)->toArray(),
            ],
            "model" => "text-embedding-ada-002",
            "object" => "list",
            "usage" => [
                "prompt_tokens" => 5,
                "total_tokens" => 5,
            ],
        ];
    }
}
