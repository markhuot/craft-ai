<?php

namespace markhuot\craftai\controllers;

use craft\helpers\UrlHelper;
use markhuot\craftai\features\Completion;
use markhuot\craftai\features\EditText;
use markhuot\craftai\models\Backend;
use markhuot\craftai\models\TextCompletionPostRequest;
use markhuot\craftai\models\TextEditPostRequest;
use markhuot\craftai\stubs\Request;

/**
 * @property Request $request
 */
class TextController extends \markhuot\craftai\web\Controller
{
    public function actionIndex()
    {
        return $this->renderTemplate('ai/_text/index');
    }

    public function actionComplete()
    {
        $this->requirePostRequest();
        $data = $this->request->getBodyParamObject(TextCompletionPostRequest::class);

        $response = Backend::for(Completion::class)->completeText($data->content);

        return $this->response(
            json: fn () => ['text' => $response->text],
            html: fn () => $this->flash('AI completion succeeded')->redirect('ai/text?'.http_build_query([
                'content' => $data->content,
                'completion' => $response->text,
            ])),
        );
    }

    public function actionEdit()
    {
        $this->requirePostRequest();
        $data = $this->request->getBodyParamObject(TextEditPostRequest::class);

        $response = Backend::for(EditText::class)->editText($data->input, $data->instructions);

        return $this->response(
            json: ['text' => $response->text],
        );
    }
}
