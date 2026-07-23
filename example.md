# orange/files — Examples

Worked examples for uploaded-file handling. See [README.md](README.md) for
the API and configuration reference.

## Bootstrapping (container service)

```php
// config/services.php
use orange\files\Files;
use orange\framework\interfaces\ContainerInterface;

'files' => fn(ContainerInterface $container): Files
    => Files::getInstance($container->config->files, $container->input),
```

The package config defaults `mimes` from the framework's output config, so
extension-style `isOneOf('png', ...)` matching works with no wiring. To use a
custom map instead, pass it — it merges over the framework defaults:

```php
'files' => function (ContainerInterface $container): Files {
    $config = $container->config->files;
    $config['mimes'] = ['heic' => 'image/heic'] + $config['mimes'];

    return Files::getInstance($config, $container->input);
},
```

## The single-file form field

```html
<form method="post" enctype="multipart/form-data">
    <input type="file" name="avatar">
</form>
```

```php
use orange\files\exceptions\FileUpload;

try {
    $avatar = container()->files->getOne('avatar');
} catch (FileUpload $e) {
    // nothing uploaded / malformed request
    show404();
}

if (!$avatar->isValid()) {
    // too big, partial, blocked... - the mapped human message
    $error = $avatar->errorMessage();
}

if (!$avatar->isOneOf('image/png', 'image/jpeg', 'image/webp')) {
    $error = 'Please upload a PNG, JPEG, or WebP image.';
}

$avatar->move(__ROOT__ . '/var/uploads/avatars', 'user-' . $userId . '.' . $avatar->extension());
```

## Multi uploads (`name="photos[]"`)

```php
// photos[0], photos[1], ... - one UploadObject per file
foreach (container()->files->get('photos') as $key => $photo) {
    if (!$photo->isValid()) {
        $errors[$key] = $photo->errorMessage();

        continue;
    }

    // keep the sanitized client filename, auto-number collisions
    $photo->move(__ROOT__ . '/var/uploads/photos', null, true);

    $saved[] = $photo->fullPath();
}
```

## Optional upload fields

`get()` throws when nothing was uploaded — for an optional field, check
first:

```php
$files = container()->files;

if ($files->has('attachment')) {
    $files->getOne('attachment')->move($attachmentDir);
}
```

## Trust the measurements, not the client

Every `user*()` accessor is client-supplied metadata; the unprefixed twin is
measured server-side after the file lands in the working directory:

```php
$upload->userMimeType();   // whatever the browser claimed - display only
$upload->mimeType();       // detected from the file CONTENT - validate with this

$upload->userSize();       // claimed
$upload->size();           // measured with filesize()

$upload->userFilename();   // raw - may contain anything
$upload->filename();       // sanitized via the framework Security service
```

```php
// size limit (2 MB) enforced on the real size
if ($upload->size() > 2 * 1024 * 1024) {
    $error = 'File too large.';
}
```

## MIME checks two ways

```php
// exact detected-MIME match
$upload->isOneOf('application/pdf');

// extension shorthand - resolved through the 'mimes' config map
// ('pdf' => 'application/pdf'), so this still checks the DETECTED type
$upload->isOneOf('pdf', 'png');
```

## Collisions

```php
// refuse to clobber: returns false, errorMessage() explains, and the object
// still points at ITS OWN temp file (safe to delete() or retry)
if (!$upload->move($dir)) {
    // "File already exists with the same name."
}

// or auto-number: photo.jpg -> photo 1.jpg -> photo 2.jpg ...
$upload->move($dir, null, true);
```

## Lifecycle

```php
$upload = container()->files->getOne('report');

// the file sits in the working directory under a content-hash name;
// if this request ends without move(), the object's destructor removes it

if ($upload->isValid() && $upload->isOneOf('csv')) {
    $upload->move($importDir);      // now permanent - survives destruction
}

// working files that somehow survive (a fatal mid-request, say) are swept
// by the next construction once they outlive 'auto cleanup seconds'
```

## Handling every upload in one pass

```php
use orange\files\exceptions\NoFilesFound;

try {
    foreach (container()->files->get() as $key => $upload) {
        // 'fieldname' or 'fieldname[N]' => UploadObject
    }
} catch (NoFilesFound) {
    // the request carried no files at all
}
```
