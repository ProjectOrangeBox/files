<?php

declare(strict_types=1);

return [
    'required file keys' => ['name', 'full_path', 'type', 'tmp_name', 'error', 'size'],
    // extension => mime map used by UploadObject::isOneOf('png', ...).
    // Defaults to the framework's output config 'mimes' key (read straight
    // from the Config service - the Output class is never instantiated), so
    // extension matching works out of the box; a caller-supplied 'mimes'
    // still merges on top. Falls back to [] when no container is up yet
    // (e.g. a config file required directly in a unit test).
    'mimes' => config('output', 'mimes', []),
    'auto cleanup seconds' => 600,
    'temporary file suffix' => '.upload-temp',
    // sprintf format
    'auto number format' => ' %d',
    // sprintf format
    'multi upload key format' => '%s[%d]',
    'error map' => [
        0 => 'There is no error, the file uploaded with success',
        1 => 'The uploaded file exceeds the upload_max_filesize directive in php.ini',
        2 => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form',
        3 => 'The uploaded file was only partially uploaded',
        4 => 'No file was uploaded',
        6 => 'Missing a temporary folder',
        7 => 'Failed to write file to disk.',
        8 => 'A PHP extension stopped the file upload.',
        199 => 'File already exists with the same name.',
    ],
    'php error map' => [
        0 => 'UPLOAD_ERR_OK',
        1 => 'UPLOAD_ERR_INI_SIZE',
        2 => 'UPLOAD_ERR_FORM_SIZE',
        3 => 'UPLOAD_ERR_PARTIAL',
        4 => 'UPLOAD_ERR_NO_FILE',
        6 => 'UPLOAD_ERR_NO_TMP_DIR',
        7 => 'UPLOAD_ERR_CANT_WRITE',
        8 => 'UPLOAD_ERR_EXTENSION',
    ]
];
