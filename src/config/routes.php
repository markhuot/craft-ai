<?php

use markhuot\craftai\features\Completion;
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

if (Backend::for(GenerateImage::class, true)) {
    $routes['images'] = [
        'route' => 'ai/images',
        'label' => 'Images',
        'controller' => 'ai/image/index',
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
