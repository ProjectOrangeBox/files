# orange/files

Orange Files package provides a lightweight upload file object helper with safe handling for temporary upload moves, validation, MIME checks, and file lifecycle operations.

## Package

- Package name: `orange/files`
- Namespace: `orange\files`
- Minimum PHP version: `8.4`
- PSR-4 autoload: `orange\\files\\` → `src/`

## Components

### `UploadObject`

Main class for single file upload management.

- Validates incoming `$_FILES`-style array keys
- Supports upload error mapping (`error map` from config)
- Uses `orange\framework\Security` for filename cleanup
- Moves uploaded file into working directory
- Derives actual size and content MIME type from disk
- Support methods:
  - `fullPath()`
  - `filename()`
  - `userFilename()`
  - `MimeType()`
  - `userMimeType()`
  - `extension()`
  - `userExtension()`
  - `size()`
  - `userSize()`
  - `isValid()`
  - `errorMessage()`
  - `isOneOf()`
  - `move($newDirectory, $newName = null, $autoNumber = false)`
  - `delete()`

## Installation

Because this is a private repro without Packagist, you can add the following to your `composer.json` repositories section:

```json
        {
            "type": "git",
            "url": "git@github.com:ProjectOrangeBox/files.git"
        }
```
Then install it with:

```bash
composer require orange/files
```

## Configuration

Base config file lives at `src/config/files.php`.

Default Keys are:

- `workingDirectory` (absolute path, writable)
- `temporary file suffix` (e.g. `.tmp`)
- `required file keys` (array of keys required in the `$_FILES` entry)
- `error map` (upload error to message map)
- `mimes` (extension => mime type mapping)
- `auto number format` (for auto-numbered move collisions)
- `auto cleanup seconds` (optional auto-clean behavior)

You can override the default config by copying the file to your projects config folder and modifying as needed.

Example config:

```php
return [
    'workingDirectory' => sys_get_temp_dir() . '/orange_files',
    'temporary file suffix' => '.tmp',
    'required file keys' => ['name', 'full_path', 'type', 'tmp_name', 'error', 'size'],
    'auto number format' => '-%d',
    'auto cleanup seconds' => 3600,
];
```

## Quick usage

Add the following to your container services:

```php
'files' => function (ContainerInterface $container) {
    $config = $container->config->files;
    $config['mimes'] = $container->{'$mimes'};

    return Files::getInstance($config, $container->input);
},
```


```php

$upload = container()->files;

$uploadFile = $upload->get('file_fieldname');

if (!$uploadFile->isValid()) {
    throw new RuntimeException($upload->errorMessage());
}

if (!$uploadFile->isOneOf('image/png', 'png')) {
    throw new RuntimeException('File type not allowed.');
}

$uploadFile->move('/var/www/uploads', null, true);

echo 'Saved to: ' . $uploadFile->fullPath();

```

## API details

- `move()` throws:
  - `orange\files\exceptions\DirectoryNotFound`
  - `orange\files\exceptions\DirectoryNotWritable`

- `delete()` throws:
  - `orange\files\exceptions\FileNotFound`

- `UploadObject` will throw `FilesFormatError`, `CouldNotLocateFile`, `CouldNotMove` on constructor failure.

## Unit tests

Run tests from the package folder:

```bash
cd vendor/orange/files
./runUnitTests.sh
```

## Contributing

1. Fork repository
2. Create branch `feature/<topic>`
3. Add tests under `unittest`
4. Keep `phpcs` style consistent with existing project rules
5. Submit PR

## License

Same license as the Orange Framework (MIT-style/open-source). Check root `LICENSE` for details.
