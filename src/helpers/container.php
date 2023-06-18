<?php

namespace markhuot\openai\helpers;

use craft\web\Request;
use craft\web\Session;
use craft\web\View;

function request(): Request
{
    if (! is_a(\Craft::$app->request, Request::class)) {
        throw new \Exception('Request is not a web request');
    }

    return \Craft::$app->request;
}
function session(): Session
{
    if (! is_a(\Craft::$app->session ?? null, Session::class)) {
        throw new \Exception('Request is not a web session');
    }

    return \Craft::$app->session;
}

function view(): View
{
    if (! is_a(\Craft::$app->view, View::class)) {
        throw new \Exception('Request is not a web view');
    }

    return \Craft::$app->view;
}
