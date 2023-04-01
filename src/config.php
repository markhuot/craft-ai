<?php

return [
    'useFakes' => true,

    'searchDriver' => 'opensearch',

    'searchDrivers' => [
        'null' => [
            'class' => \markhuot\craftai\search\NullSearch::class,
        ],
        'opensearch' => [
            'class' => \markhuot\craftai\search\OpenSearch::class,
            'hosts' => ['https://localhost:9200'],
            'basicAuthentication' => ['admin', 'admin'],
            'sslVerification' => false,
        ],
    ],
];
