<?php

namespace markhuot\craftai\backends;

use craft\elements\Asset;
use Faker\Factory;
use markhuot\craftai\models\ImageCaptionResponse;
use markhuot\craftai\models\TextCompletionResponse;
use markhuot\craftai\models\TextEditResponse;

trait HuggingFaceCompletion
{
    public function completeText(string $text): TextCompletionResponse
    {
        /** @var array<array{generated_text: string}> $body */
        $body = $this->post(
            uri: $this->getTextGenerationModel(),
            body: [
                'inputs' => $text,
            ]
        );

        $response = new TextCompletionResponse;
        $response->text = $body[0]['generated_text'];

        return $response;
    }

    /**
     * @return array<mixed>
     */
    public function completeTextFake(string $text): array
    {
        return [
            ['generated_text' => Factory::create()->paragraph(5)],
        ];
    }
}
