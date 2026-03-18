<?php

return [

    'hmac_secret' => env('IMAGES_HMAC_SECRET', '68t26clOnZB6KzleKWbgRY5J'),
    'default_disk' => env('IMAGES_DEFAULT_DISK', 's3'),
    'quality_min' => 40,
    'quality_max' => 92,
    'allowed_ext' => ['jpg', 'jpeg', 'png', 'webp', 'avif'],
    'content_types' => [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
    ],
    'resize_modes' => ['fit', 'fill'],
    'origins' => [
        'local' => [
            'disk' => env('IMAGES_LOCAL_DISK', 'media'),
            'prefix' => env('IMAGES_LOCAL_PREFIX', ''),
        ],
        's3' => [
            'disk' => env('IMAGES_S3_DISK', 's3'),
            'prefix' => env('IMAGES_S3_PREFIX', ''),
        ],
    ],

    'source_prefixes' => [
        'local:' => 'local',
        's3:' => 's3',
    ],

    'allowed_ops' => [
        'rs' => [
            'max_width' => 4000,
            'max_height' => 4000,
            'max_pixels' => 16_000_000,
        ],
    ],

    'picture' => [
        'formats' => ['avif', 'jpg'],
        'fallback_format' => 'jpg',
        'densities' => [1],
        'placeholder' => 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7',
        'breakpoints' => [
            'desktop' => '(min-width: 1200px)',
            'tablet' => '(min-width: 768px) and (max-width: 1199px)',
            'mobile' => '(max-width: 767px)',
        ],
        'devices_order' => ['desktop', 'tablet', 'mobile'],
        'exclude_types' => ['gif', 'svg', 'svg+xml'],
    ],


    'cache_ttl' => 31536000,
];
