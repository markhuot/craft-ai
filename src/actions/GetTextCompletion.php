<?php

namespace markhuot\craftai\actions;

use craft\web\Response;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use markhuot\craftai\models\TextCompletionResponse;

class GetTextCompletion
{
    function __construct(
        protected \GuzzleHttp\Client $client
    ) { }

    function handle(string $content): TextCompletionResponse
    {
        try {
            $response = $this->client->request('POST', 'https://api.openai.com/v1/completions', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . getenv('OPEN_API_KEY'),
                ],
                'body' => json_encode([
                    'model' => 'text-davinci-003',
                    'prompt' => strip_tags($content),
                    'temperature' => 0.7,
                    'max_tokens' => 256,
                    'top_p' => 1,
                    'frequency_penalty' => 0,
                    'presence_penalty' => 0,
                ], JSON_THROW_ON_ERROR),
            ]);

            $response = json_decode($response->getBody()->getContents(), true);

            $model = new TextCompletionResponse;
            $model->text = $response['choices'][0]['text'] ?? null;

            return $model;
        }
        catch (ClientException $e)
        {
            $response = new Response();
            $response->setStatusCode(500);
            $response->headers->add('content-type', 'application/json');
            $response->content = json_encode([
                'openaiErrors' => json_decode($e->getResponse()->getBody()->getContents(), true, 256,
                    JSON_THROW_ON_ERROR),
            ], JSON_THROW_ON_ERROR);
            \Craft::$app->end(500, $response);
        }
    }
}
