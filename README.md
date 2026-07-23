# Files

Uploaded-file handling: normalizes `$_FILES` (single and multi uploads) into
validated `UploadObject` instances with safe temporary storage, content-based
MIME/size detection, and collision-aware moves.

**A cookbook of worked examples lives in [example.md](example.md).**

## Example

```php
use orange\files\Files;

$files = Files::getInstance($config, container()->input);

$upload = $files->getOne('avatar');           // the single-field convenience

if (!$upload->isValid()) {
    throw new RuntimeException($upload->errorMessage());
}

if (!$upload->isOneOf('image/png', 'image/jpeg')) {
    throw new RuntimeException('File type not allowed.');
}

$upload->move('/var/www/uploads', null, true); // auto-numbers on collision

echo 'Saved to: ' . $upload->fullPath();
```

## How it works

1. `Files` pulls `$_FILES` entries through the framework `Input` service and
   builds one `UploadObject` per file — multi uploads (`name="photos[]"`)
   become `photos[0]`, `photos[1]`, ...
2. Each `UploadObject` immediately relocates the PHP temp file into the
   configured working directory — through `move_uploaded_file()` for genuine
   HTTP uploads (verifying it arrived via POST), `rename()` otherwise
   (CLI / tests) — and derives the **real** size and MIME type from disk,
   never trusting the client-supplied metadata.
3. The upload is temporary until you `move()` it: an `UploadObject` destroyed
   without a successful `move()` deletes its file; after `move()` the file
   belongs to you and destruction leaves it alone. Stale temp files older
   than `auto cleanup seconds` are swept on construction.

Each field is processed exactly once per request (processing consumes the
PHP temp file) — repeated `get()` calls return the same memoized objects.

## API

`Files`:

| Method | Purpose |
| --- | --- |
| `get(?string $fieldname = null): array` | UploadObjects for one field (or every field); throws `NoFilesFound` |
| `getOne(string $fieldname): UploadObject` | the single object for a one-file field |
| `has(string $fieldname): bool` | no-throw check for optional upload fields |

`UploadObject`:

| Method | Purpose |
| --- | --- |
| `isValid(): bool` / `errorMessage(): string` | upload error state (`error map` config) |
| `filename()` / `userFilename()` | sanitized name (framework `Security`) / raw client name |
| `mimeType()` / `userMimeType()` | detected-from-content / client-claimed |
| `size()` / `userSize()` | measured on disk / client-claimed |
| `extension()` / `userExtension()` | from the cleaned / raw filename |
| `isOneOf(string ...$oneOf): bool` | match detected MIME against types or configured extensions |
| `move(string $dir, ?string $newName = null, bool $autoNumber = false): bool` | relocate; refuses to clobber (sets `ERR_ALREADY_EXISTS`) unless `$autoNumber` |
| `fullPath(): string` | current path (working dir until `move()`, then destination) |
| `delete(): bool` | remove the file |

Every `user*()` accessor returns what the client *claimed*; the unprefixed
twin returns what the server *measured*. Validate with the unprefixed ones.

## Configuration

`src/config/files.php` (merged under anything you pass in):

| Key | Default | Purpose |
| --- | --- | --- |
| `workingDirectory` | `__ROOT__ . '/var/temp'` | writable holding area (created 0755 if missing) |
| `temporary file suffix` | `.upload-temp` | suffix for working files (targeted by cleanup) |
| `auto cleanup seconds` | `600` | sweep working files older than this; `0` disables |
| `required file keys` | name, full_path, type, tmp_name, error, size | expected `$_FILES` entry shape |
| `mimes` | `config('output', 'mimes', [])` | `ext => mime` map enabling `isOneOf('png')`-style matching — defaults to the framework output config's mimes (read from the Config service, no Output instance); pass your own to merge on top |
| `auto number format` | `' %d'` | sprintf suffix for `move(..., autoNumber: true)` |
| `multi upload key format` | `%s[%d]` | key format for multi-upload objects |
| `error map` | PHP upload errors + `199` | error code → human message |

## Exceptions

All extend `orange\files\exceptions\FileUpload`: `NoFilesFound` (nothing
uploaded), `FilesFormatError` (malformed `$_FILES` entry — every missing key
reported at once), `CouldNotLocateFile` / `CouldNotMove` (construction),
`DirectoryNotFound` / `DirectoryNotWritable` (`move()` target, working
directory), `FileNotFound` (operating on a file that is gone).

## Testing

```sh
composer test          # or: cd unittest && sh runUnitTests.sh
```
