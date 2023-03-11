<?php

return [
    'useFakes' => true,

    'driver' => 'opensearch',

    'drivers' => [
        'opensearch' => [
            'class' => \markhuot\craftai\search\OpenSearch::class,
            'hosts' => ['https://localhost:9200'],
            'basicAuthentication' => ['admin', 'admin'],
            'SSLVerification' => false,
        ],
    ],
];
