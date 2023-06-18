<?php

use markhuot\craftai\features\Chat;
use markhuot\craftai\features\Completion;
use markhuot\craftai\features\EditImage;
use markhuot\craftai\features\GenerateEmbeddings;
use markhuot\craftai\features\GenerateImage;
use markhuot\craftai\models\Backend;

$routes = [];

$routes['index'] = [
    'route' => 'ai',
    'label' => '',
    'controller' => 'ai/backend/index',
];

if (Backend::can(Completion::class)) {
    $routes['text'] = [
        'route' => 'ai/text',
        'label' => 'Text',
        'controller' => 'ai/text/index',
    ];
}

if (Backend::can(Chat::class)) {
    $routes['chat'] = [
        'route' => 'ai/chat',
        'label' => 'Chat',
        'controller' => 'ai/chat/index',
    ];
}

if (Backend::can(GenerateImage::class)) {
    $routes['image.generate'] = [
        'route' => 'ai/images/generate',
        'label' => 'Generate Images',
        'controller' => 'ai/image/generate',
    ];
}

if (Backend::can(EditImage::class)) {
    $routes['image.edit'] = [
        'route' => 'ai/images/edit',
        'label' => 'Edit Images',
        'controller' => 'ai/image/edit',
    ];
}

if (Backend::can(GenerateEmbeddings::class)) {
    $routes['ask'] = [
        'route' => 'ai/ask',
        'label' => 'Ask',
        'controller' => 'ai/ask/index',
    ];
}

$routes['backends'] = [
    'route' => 'ai/backends',
    'label' => 'Backends',
    'controller' => 'ai/backend/index',
];

$routes['backends.edit'] = [
    'route' => 'ai/backend/<id:\d+>',
    'controller' => 'ai/backend/edit',
];

$routes['backends.store'] = [
    'route' => 'ai/backend/create/<type:[a-z-]+>',
    'controller' => 'ai/backend/create',
];

return $routes;
