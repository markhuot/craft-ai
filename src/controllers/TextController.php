<?php

namespace markhuot\craftai\controllers;

use craft\web\Controller;
use markhuot\craftai\features\Completion;
use markhuot\craftai\features\Edit;
use markhuot\craftai\models\Backend;
use markhuot\craftai\models\TextEditPostRequest;
use markhuot\craftai\stubs\Request;
use markhuot\craftai\models\TextCompletionPostRequest;

/**
 * @property Request $request
 */
class TextController extends Controller
{
    function actionComplete()
    {
        $this->requirePostRequest();
        $data = $this->request->getBodyParamObject(TextCompletionPostRequest::class);

        $response = Backend::for(Completion::class)->completeText($data->content);

        return $this->asJson([
            'text' => $response->text,
        ]);
    }

    function actionEdit()
    {
        $this->requirePostRequest();
        $data = $this->request->getBodyParamObject(TextEditPostRequest::class);

        $response = Backend::for(Edit::class)->editText($data->input, $data->instructions);

        return $this->asJson([
            'text' => $response->text,
        ]);
    }
}
