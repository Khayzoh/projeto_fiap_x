<?php

declare(strict_types=1);

/*
 * Configuracao especifica da FIAP X. Manter fora de config/app.php deixa
 * explicito o que e dominio deste sistema e o que e framework.
 */
return [
    'jwt' => [
        // HS256 com segredo compartilhado: mesma estrategia documentada na Fase 3,
        // o que permite que outros servicos validem o token sem chamar a API.
        'secret' => env('JWT_SECRET', env('APP_KEY')),
        'algo' => 'HS256',
        'ttl' => (int) env('JWT_TTL_MINUTES', 120),
        'issuer' => env('JWT_ISSUER', 'fiapx-api'),
    ],

    'storage' => [
        'disk' => env('STORAGE_DISK', 's3'),
        // Disco usado apenas para assinar os links de download, com o endpoint
        // que o navegador do usuario consegue resolver.
        'public_disk' => env('STORAGE_PUBLIC_DISK', 's3_public'),
        'bucket' => env('STORAGE_BUCKET', 'fiapx-videos'),
        // Validade do link temporario de download do ZIP.
        'download_ttl_minutes' => (int) env('DOWNLOAD_URL_TTL_MINUTES', 15),
    ],

    'upload' => [
        // 500 MB. Limite tambem aplicado no nginx (client_max_body_size).
        'max_size_kb' => (int) env('UPLOAD_MAX_SIZE_KB', 512000),
        'extensions' => ['mp4', 'avi', 'mov', 'mkv', 'wmv', 'flv', 'webm'],
        'mimetypes' => [
            'video/mp4', 'video/x-msvideo', 'video/quicktime',
            'video/x-matroska', 'video/x-ms-wmv', 'video/x-flv', 'video/webm',
        ],
    ],

    'rabbitmq' => [
        'host' => env('RABBITMQ_HOST', 'rabbitmq'),
        'port' => (int) env('RABBITMQ_PORT', 5672),
        'user' => env('RABBITMQ_USER', 'fiapx'),
        'password' => env('RABBITMQ_PASSWORD', 'fiapx'),
        'vhost' => env('RABBITMQ_VHOST', '/'),
        // Precisa ser bem maior que o timeout do wait() do consumidor.
        'heartbeat' => (int) env('RABBITMQ_HEARTBEAT', 60),
        'wait_timeout' => (int) env('RABBITMQ_WAIT_TIMEOUT', 10),

        // Os nomes precisam bater exatamente com worker/internal/adapter/messaging/topology.go
        'exchange' => 'fiapx.events',
        'exchange_type' => 'topic',
        'dlx' => 'fiapx.dlx',
        'queues' => [
            'processing' => 'video.processing',
            'status' => 'video.status',
            'dlq' => 'video.processing.dlq',
        ],
        'routing_keys' => [
            'uploaded' => 'video.uploaded',
            'completed' => 'video.completed',
            'failed' => 'video.failed',
        ],
    ],

    'processing' => [
        'fps' => (int) env('PROCESSING_FPS', 1),
    ],
];
