<?php

return [
    'useFakes' => true,

    'searchDriver' => 'opensearch',

    'searchDrivers' => [
        'opensearch' => [
            'class' => \markhuot\craftai\search\OpenSearch::class,
            'hosts' => ['https://localhost:9200'],
            'basicAuthentication' => ['admin', 'admin'],
            'sslVerification' => false,
        ],
    ],
];
