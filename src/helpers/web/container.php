<?php

namespace markhuot\openai\helpers\web;

use craft\services\Config;
use craft\services\Elements;
use craft\web\Request;
use craft\web\Session;
use craft\web\User;
use craft\web\View;

function app(): \craft\web\Application
{
    $app = \Craft::$app;
    if (! is_a($app, \craft\web\Application::class)) {
        throw new \Exception('App is not a web application');
    }

    return $app;
}

function request(): Request
{
    return app()->request;
}
function session(): Session
{
    return app()->session;
}

function view(): View
{
    return app()->view;
}

function auth(): User
{
    return app()->user;
}

function elements(): Elements
{
    return app()->elements;
}

function config(): Config
{
    return app()->config;
}
