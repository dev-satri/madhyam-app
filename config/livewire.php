<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Temporary File Uploads
    |--------------------------------------------------------------------------
    |
    | Livewire handles file uploads by storing them in a temporary directory
    | before they are validated and stored permanently.
    |
    */

    'temporary_file_upload' => [
        'disk' => null,        // Example: 'local', 's3'               | Default: 'default'
        'rules' => ['file', 'max:204800'], // 200MB in KB
        'directory' => null,   // Example: 'tmp'                       | Default: 'livewire-tmp'
        'middleware' => null,  // Example: 'throttle:5,1'              | Default: 'throttle:60,1'
        'preview_mimes' => [   // Supported file types for temporary pre-signed file URLs
            'png', 'gif', 'bmp', 'svg', 'wav', 'mp4',
            'mov', 'avi', 'wmv', 'mp3', 'm4a',
            'jpg', 'jpeg', 'mpga', 'webp', 'wma',
            'pdf', 'doc', 'docx', 'xls', 'xlsx',
            'ppt', 'pptx', 'txt', 'csv', 'zip',
        ],
        'max_upload_time' => 300, // 5 minutes — generous for large files
        'cleanup' => true,
    ],

];
