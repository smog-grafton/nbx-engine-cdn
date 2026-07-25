<?php

return [
    'paths' => ['api/v1/nbx/uploads/*'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'OPTIONS'],
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env(
            'NBX_CREATOR_UPLOAD_ORIGINS',
            'https://naraboxtv.com,https://www.naraboxtv.com,http://localhost:3000'
        ))
    ))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => [
        'Content-Type',
        'Content-Length',
        'Content-Range',
        'X-NBX-Upload-Token',
        'X-Chunk-SHA256',
        'X-File-SHA256',
    ],
    'exposed_headers' => ['X-NBX-Chunk-SHA256'],
    'max_age' => 3600,
    'supports_credentials' => false,
];
