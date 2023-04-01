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

if (Backend::for(Completion::class, true)) {
    $routes['text'] = [
        'route' => 'ai/text',
        'label' => 'Text',
        'controller' => 'ai/text/index',
    ];
}

if (Backend::for(Chat::class, true)) {
    $routes['chat'] = [
        'route' => 'ai/chat',
        'label' => 'Chat',
        'controller' => 'ai/chat/index',
    ];
}

if (Backend::for(GenerateImage::class, true)) {
    $routes['image.generate'] = [
        'route' => 'ai/images/generate',
        'label' => 'Generate Images',
        'controller' => 'ai/image/generate',
    ];
}

if (Backend::for(EditImage::class, true)) {
    $routes['image.edit'] = [
        'route' => 'ai/images/edit',
        'label' => 'Edit Images',
        'controller' => 'ai/image/edit',
    ];
}

if (Backend::for(GenerateEmbeddings::class, true)) {
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
