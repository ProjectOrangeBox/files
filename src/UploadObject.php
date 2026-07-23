<?php

declare(strict_types=1);

namespace orange\files;

use orange\files\exceptions\CouldNotLocateFile;
use orange\files\exceptions\CouldNotMove;
use orange\files\exceptions\DirectoryNotFound;
use orange\files\exceptions\DirectoryNotWritable;
use orange\files\exceptions\FileNotFound;
use orange\files\exceptions\FilesFormatError;
use orange\framework\Security;

/**
 * Class UploadObject
 *
 * Represents a single uploaded file and provides helper methods for validating,
 * inspecting, moving, and deleting the temporarily stored upload.
 *
 * While the file sits in the working directory it is temporary - if the
 * object is destroyed without move() being called, the file is removed.
 * After a successful move() the file is yours: destruction leaves it alone.
 *
 * @package orange\files
 */
class UploadObject
{
    /** @var int move() collision pseudo error code (see 'error map' config) */
    public const ERR_ALREADY_EXISTS = 199;

    /** @var string Local file path (working directory until move(), then the destination) */
    protected string $localTmpPath = '';

    /** @var int Actual file size in bytes (from temporary storage) */
    protected int $realSize = 0;

    /** @var string Detected MIME type (from temporary storage) */
    protected string $realType = '';

    /** @var string Sanitized filename to use for destination operations */
    protected string $cleanName = '';

    /** @var int Upload error code from the upload process */
    protected int $errorNum = 0;

    /** @var bool True while the file still lives in the working directory */
    protected bool $isTemporary = false;

    /**
     * UploadObject constructor.
     *
     * Validates required $_FILES keys, checks the upload error state, moves
     * the temporary file to a controlled working directory, and computes actual
     * size and MIME type.
     *
     * @param array $config Configuration options for upload handling.
     * @param array $file  Standard $_FILES array for a single uploaded file.
     *
     * @throws FilesFormatError On missing required keys or unknown error codes.
     * @throws CouldNotLocateFile could not locate the file
     * @throws CouldNotMove could not move the file
     */
    public function __construct(protected array $config, protected array $file)
    {
        // $file is a standard $_FILES array - report EVERY missing key at once
        $missing = [];

        foreach ($this->config['required file keys'] as $key) {
            if (!isset($file[$key])) {
                $missing[] = $key;
            }
        }

        if (!empty($missing)) {
            throw new FilesFormatError('The following file key(s) are missing: "' . implode(', ', $missing) . '"');
        }

        // get the error number
        $this->errorNum = $file['error'];

        if (!array_key_exists($this->errorNum, $this->config['error map'])) {
            throw new FilesFormatError('Unknown file error number ' . $this->errorNum);
        } elseif ($this->errorNum == 0) {
            // clean filename using Orange security class
            $this->cleanName = Security::getInstance([])->cleanFilename($file['name']);

            if (!file_exists($file['tmp_name'])) {
                throw new CouldNotLocateFile('Could not locate "' . $file['tmp_name'] . '"');
            }

            // move to a local folder from where ever you might have PHP configured for
            $this->localTmpPath = $this->config['workingDirectory'] . '/' . md5_file($file['tmp_name']) . '-' . md5($file['tmp_name']) . $this->config['temporary file suffix'];

            // a genuine HTTP upload only moves through move_uploaded_file() -
            // it verifies the file arrived via POST, closing off tricks that
            // point tmp_name at an arbitrary server file. The rename() branch
            // covers CLI / testing where no real upload exists
            $moved = is_uploaded_file($file['tmp_name'])
                ? move_uploaded_file($file['tmp_name'], $this->localTmpPath)
                : rename($file['tmp_name'], $this->localTmpPath);

            if (!$moved) {
                throw new CouldNotMove('Could not move uploaded file to ' . $this->config['workingDirectory']);
            }

            // in the working directory the file is ours to clean up
            $this->isTemporary = true;

            // now determine the filesize ourself not what was passed
            $this->realSize = (int)filesize($this->localTmpPath);

            // now determine the file type ourself not what was passed
            $this->realType = (string)mime_content_type($this->localTmpPath);
        }
    }

    /**
     * Get the current file path - working directory until move(), then the
     * destination.
     *
     * @return string
     */
    public function fullPath(): string
    {
        return $this->localTmpPath;
    }

    /**
     * Get the cleaned upload filename.
     *
     * @return string
     */
    public function filename(): string
    {
        return $this->cleanName;
    }

    /**
     * Get original user-provided filename from $_FILES metadata.
     *
     * @return string
     */
    public function userFilename(): string
    {
        return $this->file['name'];
    }

    /**
     * Get detected MIME type from the file contents.
     *
     * @return string
     */
    public function mimeType(): string
    {
        return $this->realType;
    }

    /**
     * Get user-provided MIME type from $_FILES metadata.
     *
     * @return string
     */
    public function userMimeType(): string
    {
        return $this->file['type'];
    }

    /**
     * Get file extension from cleaned filename.
     *
     * @return string
     */
    public function extension(): string
    {
        return pathinfo($this->cleanName, PATHINFO_EXTENSION);
    }

    /**
     * Get user-provided file extension from original filename.
     *
     * @return string
     */
    public function userExtension(): string
    {
        return pathinfo($this->file['name'], PATHINFO_EXTENSION);
    }

    /**
     * Get actual file size in bytes.
     *
     * @return int
     */
    public function size(): int
    {
        return $this->realSize;
    }

    /**
     * Get user-provided file size from $_FILES metadata.
     *
     * @return int
     */
    public function userSize(): int
    {
        return $this->file['size'];
    }

    /**
     * Check whether upload was successful and no error code is set.
     *
     * @return bool
     */
    public function isValid(): bool
    {
        return $this->errorNum === 0;
    }

    /**
     * Get mapped error message based on upload error code.
     *
     * @return string
     */
    public function errorMessage(): string
    {
        return $this->config['error map'][$this->errorNum];
    }

    /**
     * Check whether actual MIME type matches any allowed type or configured
     * MIME for the provided pseudo extension values.
     *
     * @param string ...$oneOf One or more MIME types or extension keys to test.
     *
     * @return bool
     */
    public function isOneOf(string ...$oneOf): bool
    {
        // try exact mime match
        if (in_array($this->realType, $oneOf, true)) {
            return true;
        }

        // try to match on pseudo extension ('png' matches when config maps
        // png => image/png); no 'mimes' config means no extension matching
        foreach ($this->config['mimes'] ?? [] as $ext => $mime) {
            if ($this->realType === $mime && in_array((string)$ext, $oneOf, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Move the temporary file to a new directory and optionally rename.
     *
     * On success the object now points at the destination and the file is no
     * longer treated as temporary (destruction leaves it in place). On a
     * collision (without $autoNumber) nothing moves: the object keeps
     * pointing at the temporary file and the error code is set to
     * ERR_ALREADY_EXISTS.
     *
     * @param string      $newDirectory Target destination directory.
     * @param string|null $newName      Optional new filename; defaults to cleaned name.
     * @param bool        $autoNumber   Whether to append a sequence number on collision.
     *
     * @return bool True on success, false otherwise.
     *
     * @throws FileNotFound When the temporary file no longer exists.
     * @throws DirectoryNotFound When target directory is not found.
     * @throws DirectoryNotWritable When target directory is not writable.
     */
    public function move(string $newDirectory, ?string $newName = null, bool $autoNumber = false): bool
    {
        $this->exists();

        $newDirectory = rtrim($newDirectory, '/');

        if (!is_dir($newDirectory)) {
            throw new DirectoryNotFound('The location you would like to move to "' . $newDirectory . '" is not a directory.');
        }

        if (!is_writable($newDirectory)) {
            throw new DirectoryNotWritable('The location you would like to move to "' . $newDirectory . '" is not writable.');
        }

        $newName ??= $this->cleanName;

        $destination = $newDirectory . DIRECTORY_SEPARATOR . $newName;

        if ($autoNumber) {
            $destination = $this->findNextNumber($destination);
        }

        if (file_exists($destination)) {
            // refuse to clobber - and critically, keep pointing at OUR
            // temporary file, never at the existing destination file
            $this->errorNum = self::ERR_ALREADY_EXISTS;

            return false;
        }

        if (!rename($this->localTmpPath, $destination)) {
            return false;
        }

        // the file is now the caller's - destruction must leave it alone
        $this->localTmpPath = $destination;
        $this->isTemporary = false;

        return true;
    }

    /**
     * Delete the currently stored file from disk.
     *
     * @return bool True on successful deletion.
     *
     * @throws FileNotFound If file does not exist.
     */
    public function delete(): bool
    {
        $this->exists();

        return unlink($this->localTmpPath);
    }

    /**
     * Find the next available filename by appending an auto number suffix.
     *
     * @param string $filePath Current targeted file path.
     *
     * @return string New non-colliding file path.
     */
    protected function findNextNumber(string $filePath): string
    {
        $pathinfo = pathinfo($filePath);

        // extensionless files get no trailing dot
        $extension = isset($pathinfo['extension']) ? '.' . $pathinfo['extension'] : '';

        $newFilePath = $filePath;
        $num = 1;

        while (file_exists($newFilePath)) {
            $newFilePath = $pathinfo['dirname'] . DIRECTORY_SEPARATOR . $pathinfo['filename'] . sprintf($this->config['auto number format'], $num++) . $extension;
        }

        return $newFilePath;
    }

    /**
     * Ensure the internal temporary file path exists.
     *
     * @throws FileNotFound if the current temporary file is missing.
     */
    protected function exists(): void
    {
        if (!file_exists($this->localTmpPath)) {
            throw new FileNotFound($this->localTmpPath . ' does not exist.');
        }
    }

    /**
     * Destructor used to clean up remaining file resources.
     *
     * Only files still in their temporary state are removed - a file that was
     * successfully move()d belongs to the caller and is left in place.
     */
    public function __destruct()
    {
        if ($this->isTemporary && file_exists($this->localTmpPath)) {
            @unlink($this->localTmpPath);
        }
    }
}
