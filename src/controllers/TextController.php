<?php

namespace markhuot\craftai\controllers;

use markhuot\craftai\features\Completion;
use markhuot\craftai\features\EditText;
use markhuot\craftai\models\Backend;
use markhuot\craftai\models\PendingCall;
use markhuot\craftai\models\TextCompletionPostRequest;
use markhuot\craftai\models\TextCompletionResponse;
use markhuot\craftai\models\TextEditPostRequest;
use markhuot\craftai\stubs\Request;
use yii\web\Response;

/**
 * @property Request $request
 */
class TextController extends \markhuot\craftai\web\Controller
{
    public function actionIndex(): Response
    {
        $response = \markhuot\craftai\models\Response::find()->where(['id' => 1])->one();
        dd($response->finish()->text);

        return $this->renderTemplate('ai/_text/index', [
            'backends' => Backend::allFor(Completion::class),
        ]);
    }

    public function actionComplete(): Response
    {
        $data = $this->request->getBodyParamObject(TextCompletionPostRequest::class);

        $response = ($data->backend ?? Backend::for(Completion::class))->completeText($data->content);

        return $this->response(
            json: fn () => ['text' => $response->text],
            html: fn () => $this->flash('AI completion succeeded')
                ->redirect('ai/text?'.http_build_query([
                    'content' => $data->content,
                    'completion' => $response->text,
                ])),
        );
    }

    public function actionEdit(): Response
    {
        $this->requirePostRequest();
        $data = $this->request->getBodyParamObject(TextEditPostRequest::class);

        $response = Backend::for(EditText::class)->editText($data->input, $data->instructions);

        return $this->response(
            json: ['text' => $response->text],
        );
    }
}
