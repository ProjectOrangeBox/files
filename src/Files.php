<?php

declare(strict_types=1);

namespace orange\files;

use orange\files\exceptions\DirectoryNotWritable;
use orange\files\exceptions\NoFilesFound;
use orange\framework\base\Factory;
use orange\framework\interfaces\InputInterface;
use orange\framework\traits\ConfigurationTrait;

/**
 * Class Files
 *
 * Normalizes and validates uploaded files, converting them into UploadObject
 * instances. Handles single file and multi-file uploads, and performs cleanup
 * of temporary files.
 *
 * Each upload field is processed exactly once per request - processing moves
 * the PHP temporary file into the working directory, so it cannot be
 * repeated. Results are memoized: asking for the same field (or everything)
 * again returns the same UploadObject instances.
 *
 * @package orange\files
 */
class Files extends Factory
{
    use ConfigurationTrait;

    protected string $workingDirectory;

    /** @var array<string, UploadObject> processed uploads keyed by field name (field[N] for multi uploads) */
    protected array $uploadObjects = [];

    /** @var array<string, array<int, string>> uploadObjects keys per processed field name */
    protected array $processedFields = [];

    /**
     * Files constructor.
     *
     * @param array          $config Configuration options for file handling.
     * @param InputInterface $input  Input bridge to retrieve uploaded files.
     *
     * @throws DirectoryNotWritable When the temporary directory cannot be created or is not writable.
     */
    protected function __construct(array $config, protected InputInterface $input)
    {
        $this->config = $this->mergeConfigWith($config);

        // make sure we have a working directory for temporary files and that it is writable
        $this->workingDirectory = ($this->config['workingDirectory'] ??= __ROOT__ . '/var/temp');

        // the !is_dir() recheck covers a concurrent request creating it between our two calls
        if (!is_dir($this->workingDirectory) && !mkdir($this->workingDirectory, 0755, true) && !is_dir($this->workingDirectory)) {
            throw new DirectoryNotWritable('Working directory "' . $this->workingDirectory . '" could not be created.');
        }

        if (!is_writable($this->workingDirectory)) {
            throw new DirectoryNotWritable('Working directory "' . $this->workingDirectory . '" is not writable.');
        }

        $this->cleanUp($this->config['auto cleanup seconds']);
    }

    /**
     * Whether the request carries an upload under the given field name.
     *
     * The no-throw companion to get() for optional upload fields.
     *
     * @param string $fieldname Upload field name.
     * @return bool
     */
    public function has(string $fieldname): bool
    {
        // already processed counts even though the raw input was consumed
        return isset($this->processedFields[$fieldname]) || $this->input->file($fieldname, UNDEFINED) !== UNDEFINED;
    }

    /**
     * Return UploadObjects for one field - or for every uploaded field.
     *
     * Single uploads key by field name; multi uploads (name="photos[]") key
     * by the configured 'multi upload key format' (photos[0], photos[1], ...).
     * Safe to call repeatedly - each field is processed once and memoized.
     *
     * @param null|string $fieldname Field to fetch, or null for all fields.
     * @return array<string, UploadObject>
     *
     * @throws NoFilesFound When the field (or the whole request) has no files.
     */
    public function get(?string $fieldname = null): array
    {
        if ($fieldname !== null) {
            return $this->processField($fieldname);
        }

        $files = $this->input->file(null, UNDEFINED);

        if ($files === UNDEFINED || $files === []) {
            // nothing new - but everything already processed still counts
            if ($this->uploadObjects === []) {
                throw new NoFilesFound('No files found.');
            }

            return $this->uploadObjects;
        }

        foreach (array_keys($files) as $name) {
            $this->processField((string)$name);
        }

        return $this->uploadObjects;
    }

    /**
     * Return the single UploadObject for a field.
     *
     * The convenience accessor for the common one-file form field - no array
     * unwrapping. For a multi upload field this returns the first file.
     *
     * @param string $fieldname Upload field name.
     * @return UploadObject
     *
     * @throws NoFilesFound When the field has no files.
     */
    public function getOne(string $fieldname): UploadObject
    {
        $objects = $this->processField($fieldname);

        return $objects[array_key_first($objects)];
    }

    /**
     * Process a field once and return its UploadObjects (memoized).
     *
     * @param string $fieldname Upload field name.
     * @return array<string, UploadObject>
     *
     * @throws NoFilesFound When the field has no files.
     */
    protected function processField(string $fieldname): array
    {
        // processing consumes the PHP temp file, so it can only happen once -
        // afterwards serve the memoized objects
        if (!isset($this->processedFields[$fieldname])) {
            $file = $this->input->file($fieldname, UNDEFINED);

            if ($file === UNDEFINED) {
                throw new NoFilesFound('No files found for "' . $fieldname . '".');
            }

            $this->processedFields[$fieldname] = $this->processOne($fieldname, $file);
        }

        return array_intersect_key($this->uploadObjects, array_flip($this->processedFields[$fieldname]));
    }

    /**
     * Process a single upload field (single file or array of files).
     *
     * @param string $fieldname Upload field name.
     * @param array  $file      $_FILES-style array for field entry.
     * @return array<int, string> The uploadObjects keys created for this field.
     */
    protected function processOne(string $fieldname, array $file): array
    {
        $keys = [];

        if (is_array($file['name'])) {
            // multi upload: name="field[]" - $_FILES groups by attribute, regroup per file
            foreach (array_keys($file['name']) as $index) {
                $singleFile = [];

                foreach ($this->config['required file keys'] as $key) {
                    $singleFile[$key] = $file[$key][$index];
                }

                $keys[] = $objectKey = sprintf($this->config['multi upload key format'], $fieldname, $index);

                $this->uploadObjects[$objectKey] = new UploadObject($this->config, $singleFile);
            }
        } else {
            $keys[] = $fieldname;

            $this->uploadObjects[$fieldname] = new UploadObject($this->config, $file);
        }

        return $keys;
    }

    /**
     * Remove any temporary upload files older than the configured threshold.
     *
     * @param int $seconds Age in seconds for files to be considered stale.
     * @return void
     */
    protected function cleanUp(int $seconds): void
    {
        // run the clean up on working directory based on config
        if ($seconds > 0) {
            foreach (glob($this->workingDirectory . DIRECTORY_SEPARATOR . '*' . $this->config['temporary file suffix']) as $uploadTempFile) {
                if (filemtime($uploadTempFile) < time() - $seconds) {
                    unlink($uploadTempFile);
                }
            }
        }
    }
}
