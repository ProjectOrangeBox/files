<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../../vendor/autoload.php';
require_once __DIR__ . '/../../../../vendor/orange/framework/src/helpers/wrappers.php';

use orange\files\UploadObject;
use PHPUnit\Framework\TestCase;

class UploadObjectTest extends TestCase
{
    private string $workingDir;

    protected function setUp(): void
    {
        if (!defined('UNDEFINED')) {
            define('UNDEFINED', chr(0));
        }

        $this->workingDir = sys_get_temp_dir() . '/orange_files_upload_test_' . uniqid();
        mkdir($this->workingDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->workingDir);
    }

    private function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $fileinfo) {
            $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
            $todo($fileinfo->getRealPath());
        }

        rmdir($path);
    }

    public function testUploadObjectCanBeConstructedAndProvidesExpectedProperties(): void
    {
        $config = require __DIR__ . '/../src/config/files.php';
        $config['workingDirectory'] = $this->workingDir;
        $config['auto cleanup seconds'] = 0;
        $config['mimes'] = ['txt' => 'text/plain'];

        $content = 'hello world';
        $tmpSource = tempnam(sys_get_temp_dir(), 'orange_upload_');
        file_put_contents($tmpSource, $content);

        $fileData = [
            'name' => 'example.txt',
            'full_path' => $tmpSource,
            'type' => 'text/plain',
            'tmp_name' => $tmpSource,
            'error' => 0,
            'size' => strlen($content),
        ];

        $upload = new UploadObject($config, $fileData);

        $this->assertTrue($upload->isValid());
        $this->assertSame('example.txt', $upload->userFilename());
        $this->assertSame('example.txt', $upload->filename());
        $this->assertSame('txt', $upload->extension());
        $this->assertSame('text/plain', $upload->userMimeType());
        $this->assertSame(strlen($content), $upload->userSize());
        $this->assertSame(strlen($content), $upload->size());
        $this->assertStringContainsString($this->workingDir, $upload->fullPath());
        $this->assertTrue($upload->isOneOf('text/plain'));
        $this->assertTrue($upload->isOneOf('txt'));

        $this->assertSame($config['error map'][0], $upload->errorMessage());

        // ensure the original temp source was moved from source path
        $this->assertFileDoesNotExist($tmpSource);
        $this->assertFileExists($upload->fullPath());

        // move to a destination folder
        $destination = $this->workingDir . '/dest';
        mkdir($destination, 0777, true);

        $this->assertTrue($upload->move($destination));
        $this->assertStringStartsWith($destination, $upload->fullPath());
        $this->assertFileExists($upload->fullPath());

        $this->assertTrue($upload->delete());
        $this->assertFileDoesNotExist($upload->fullPath());
    }

    public function testUploadObjectWithErrorDoesNotMoveFile(): void
    {
        $config = require __DIR__ . '/../src/config/files.php';
        $config['workingDirectory'] = $this->workingDir;
        $config['auto cleanup seconds'] = 0;

        $tmpSource = tempnam(sys_get_temp_dir(), 'orange_upload_error_');
        file_put_contents($tmpSource, 'x');

        $fileData = [
            'name' => 'error.txt',
            'full_path' => $tmpSource,
            'type' => 'text/plain',
            'tmp_name' => $tmpSource,
            'error' => 4,
            'size' => 1,
        ];

        $upload = new UploadObject($config, $fileData);

        $this->assertFalse($upload->isValid());
        $this->assertSame($config['error map'][4], $upload->errorMessage());

        // this is a no-op for error state, so file should remain where it was
        $this->assertFileExists($tmpSource);

        unlink($tmpSource);
    }
}
