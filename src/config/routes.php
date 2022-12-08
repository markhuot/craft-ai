<?php

return [
    'index' => [
        'route' => 'ai',
        'label' => '',
        'controller' => 'ai/backend/index',
    ],
    'text' => [
        'route' => 'ai/test',
        'label' => 'Text',
        'controller' => 'ai/text/index',
    ],
    'images' => [
        'route' => 'ai/images',
        'label' => 'Images',
        'controller' => 'ai/image/index',
    ],
    'backends' => [
        'route' => 'ai/backends',
        'label' => 'Backends',
        'controller' => 'ai/backend/index',
    ],
    'backends.edit' => [
        'route' => 'ai/backend/<id:\d+>',
        'controller' => 'ai/backend/edit',
    ],
    'backends.store' => [
        'route' => 'ai/backend/create/<type:[a-z-]+>',
        'controller' => 'ai/backend/create',
    ],
];
