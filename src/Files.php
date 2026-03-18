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
 * @package orange\files
 */
class Files extends Factory
{
    use ConfigurationTrait;

    protected string $workingDirectory;
    protected array $uploadObjects = [];

    /**
     * Files constructor.
     *
     * @param array          $config Configuration options for file handling.
     * @param InputInterface $input  Input bridge to retrieve uploaded files.
     *
     * @throws DirectoryNotWritable When the temporary directory is not writable.
     */
    protected function __construct(array $config, protected InputInterface $input)
    {
        $this->config = $this->mergeConfigWith($config);

        // make sure we have a working directory for temporary files and that it is writable
        $this->workingDirectory = $this->config['workingDirectory'] = $this->config['workingDirectory'] ?? __ROOT__ . '/var/temp';

        if (!is_dir($this->workingDirectory)) {
            mkdir($this->workingDirectory, 0777, true);
        }

        if (!is_writable($this->workingDirectory)) {
            throw new DirectoryNotWritable('Working directory "' . $this->workingDirectory . '" is not writable.');
        }

        $this->cleanUp($this->config['auto cleanup seconds']);
    }

    /**
     * Return a array of ALL $_FILES as uploadObjects
     *
     * @param null|string $fieldname
     * @return array
     *
     * @throws NoFilesFound No files found
     */
    public function get(?string $fieldname = null): array
    {
        $files = $this->input->file($fieldname, UNDEFINED);

        if ($files == UNDEFINED) {
            throw new NoFilesFound('No files found.');
        }

        if ($fieldname) {
            // single
            $this->processOne($fieldname, $files);
        } else {
            // loop over all $_FILES
            foreach ($files as $fieldname => $files) {
                $this->processOne($fieldname, $files);
            }
        }

        return $this->uploadObjects;
    }

    /**
     * Process a single upload field (single file or array of files).
     *
     * @param string $fieldname Upload field name.
     * @param array  $file      $_FILES-style array for field entry.
     */
    protected function processOne(string $fieldname, array $file)
    {
        if (is_array($file['name'])) {
            foreach (array_keys($file['name']) as $index) {
                foreach ($this->config['required file keys'] as $key) {
                    $singleFile[$key] = $file[$key][$index];
                }

                $this->uploadObjects[sprintf($this->config['multi upload key format'], $fieldname, $index)] = new UploadObject($this->config, $singleFile);
            }
        } else {
            $this->uploadObjects[$fieldname] = new UploadObject($this->config, $file);
        }
    }

    /**
     * Remove any temporary upload files older than the configured threshold.
     *
     * @param int $seconds Age in seconds for files to be considered stale.
     */
    protected function cleanUp(int $seconds)
    {
        // run the clean up on working directory based on config
        if ($this->config['auto cleanup seconds'] > 0) {
            foreach (glob($this->workingDirectory . DIRECTORY_SEPARATOR . '*' . $this->config['temporary file suffix']) as $uploadTempFile) {
                if (filemtime($uploadTempFile) < time() - $seconds) {
                    unlink($uploadTempFile);
                }
            }
        }
    }
}
