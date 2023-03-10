<?php

return [
    'useFakes' => true,

    'driver' => 'opensearch',

    'drivers' => [
        'elasticsearch' => [
            'class' => \markhuot\craftai\search\Elasticsearch::class,
            'hosts' => ['https://localhost:9200'],
            'basicAuthentication' => ['admin', 'admin'],
            'SSLVerification' => false,
        ],
        'opensearch' => [
            'class' => \markhuot\craftai\search\OpenSearch::class,
            'hosts' => ['https://localhost:9200'],
            'basicAuthentication' => ['admin', 'admin'],
            'SSLVerification' => false,
        ],
    ],
];
