<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../../vendor/autoload.php';
require_once __DIR__ . '/../../../../vendor/orange/framework/src/helpers/wrappers.php';

use orange\files\Files;
use orange\files\UploadObject;
use orange\files\exceptions\NoFilesFound;
use orange\framework\interfaces\InputInterface;
use PHPUnit\Framework\TestCase;

class FilesTest extends TestCase
{
    private string $workingDir;

    protected function setUp(): void
    {
        if (!defined('UNDEFINED')) {
            define('UNDEFINED', chr(0));
        }

        $this->workingDir = sys_get_temp_dir() . '/orange_files_files_test_' . uniqid();
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

    public function testGetSingleFileReturnsUploadObject(): void
    {
        $config = require __DIR__ . '/../src/config/files.php';
        $config['workingDirectory'] = $this->workingDir;
        $config['auto cleanup seconds'] = 0;

        $content = 'abc';
        $tmpSource = tempnam(sys_get_temp_dir(), 'orange_files_');
        file_put_contents($tmpSource, $content);

        $fileData = [
            'name' => 'single.txt',
            'full_path' => $tmpSource,
            'type' => 'text/plain',
            'tmp_name' => $tmpSource,
            'error' => 0,
            'size' => strlen($content),
        ];

        $input = $this->createMock(InputInterface::class);

        $input->method('file')
            ->with('upload', UNDEFINED)
            ->willReturn($fileData);

        $files = Files::newInstance($config, $input);

        $uploadObjects = $files->get('upload');

        $this->assertArrayHasKey('upload', $uploadObjects);
        $this->assertInstanceOf(UploadObject::class, $uploadObjects['upload']);

        // cleanup object so destructor does not fail
        unset($uploadObjects);
    }

    public function testGetMultipleFilesReturnsMultipleUploadObjects(): void
    {
        $config = require __DIR__ . '/../src/config/files.php';
        $config['workingDirectory'] = $this->workingDir;
        $config['auto cleanup seconds'] = 0;

        $tmpFile1 = tempnam(sys_get_temp_dir(), 'orange_files_');
        file_put_contents($tmpFile1, 'x');
        $tmpFile2 = tempnam(sys_get_temp_dir(), 'orange_files_');
        file_put_contents($tmpFile2, 'y');

        $filesGrouped = [
            'name' => ['a.txt', 'b.txt'],
            'full_path' => [$tmpFile1, $tmpFile2],
            'type' => ['text/plain', 'text/plain'],
            'tmp_name' => [$tmpFile1, $tmpFile2],
            'error' => [0, 0],
            'size' => [1, 1],
        ];

        $input = $this->createMock(InputInterface::class);
        $input->method('file')
            ->with(null, UNDEFINED)
            ->willReturn(['files' => $filesGrouped]);

        $files = Files::newInstance($config, $input);

        $uploadObjects = $files->get();

        $this->assertCount(2, $uploadObjects);
        $this->assertArrayHasKey('files[0]', $uploadObjects);
        $this->assertArrayHasKey('files[1]', $uploadObjects);
        $this->assertInstanceOf(UploadObject::class, $uploadObjects['files[0]']);
        $this->assertInstanceOf(UploadObject::class, $uploadObjects['files[1]']);

        unset($uploadObjects);
    }

    public function testGetThrowsNoFilesFoundWhenNoFiles(): void
    {
        $config = require __DIR__ . '/../src/config/files.php';
        $config['workingDirectory'] = $this->workingDir;
        $config['auto cleanup seconds'] = 0;

        $input = $this->createMock(InputInterface::class);
        $input->method('file')
            ->with(null, UNDEFINED)
            ->willReturn(UNDEFINED);

        $files = Files::newInstance($config, $input);

        $this->expectException(NoFilesFound::class);

        $files->get();
    }
}
