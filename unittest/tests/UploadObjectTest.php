<?php

declare(strict_types=1);

use orange\files\UploadObject;
use orange\files\exceptions\FileNotFound;
use orange\files\exceptions\FilesFormatError;
use PHPUnit\Framework\TestCase;

class UploadObjectTest extends TestCase
{
    private string $workingDir;

    protected function setUp(): void
    {
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

    private function config(array $overrides = []): array
    {
        $config = require __DIR__ . '/../../src/config/files.php';
        $config['workingDirectory'] = $this->workingDir;
        $config['auto cleanup seconds'] = 0;

        return $overrides + $config;
    }

    private function fileData(string $name = 'example.txt', string $content = 'hello world', int $error = 0): array
    {
        $tmpSource = tempnam(sys_get_temp_dir(), 'orange_upload_');
        file_put_contents($tmpSource, $content);

        return [
            'name' => $name,
            'full_path' => $tmpSource,
            'type' => 'text/plain',
            'tmp_name' => $tmpSource,
            'error' => $error,
            'size' => strlen($content),
        ];
    }

    public function testUploadObjectCanBeConstructedAndProvidesExpectedProperties(): void
    {
        $config = $this->config(['mimes' => ['txt' => 'text/plain']]);

        $content = 'hello world';
        $fileData = $this->fileData('example.txt', $content);
        $tmpSource = $fileData['tmp_name'];

        $upload = new UploadObject($config, $fileData);

        $this->assertTrue($upload->isValid());
        $this->assertSame('example.txt', $upload->userFilename());
        $this->assertSame('example.txt', $upload->filename());
        $this->assertSame('txt', $upload->extension());
        $this->assertSame('text/plain', $upload->userMimeType());
        $this->assertSame('text/plain', $upload->mimeType());
        $this->assertSame(strlen($content), $upload->userSize());
        $this->assertSame(strlen($content), $upload->size());
        $this->assertStringContainsString($this->workingDir, $upload->fullPath());
        $this->assertTrue($upload->isOneOf('text/plain'));
        $this->assertTrue($upload->isOneOf('txt'));
        $this->assertFalse($upload->isOneOf('image/png', 'png'));

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
        $config = $this->config();

        $fileData = $this->fileData('error.txt', 'x', 4);
        $tmpSource = $fileData['tmp_name'];

        $upload = new UploadObject($config, $fileData);

        $this->assertFalse($upload->isValid());
        $this->assertSame($config['error map'][4], $upload->errorMessage());

        // this is a no-op for error state, so file should remain where it was
        $this->assertFileExists($tmpSource);

        unlink($tmpSource);
    }

    public function testDestructorRemovesUnmovedTemporaryFile(): void
    {
        $upload = new UploadObject($this->config(), $this->fileData());

        $tempPath = $upload->fullPath();
        $this->assertFileExists($tempPath);

        // destroyed without move() - the temp file is cleaned up
        unset($upload);

        $this->assertFileDoesNotExist($tempPath);
    }

    public function testDestructorKeepsMovedFile(): void
    {
        $destination = $this->workingDir . '/dest';
        mkdir($destination, 0777, true);

        $upload = new UploadObject($this->config(), $this->fileData());

        $this->assertTrue($upload->move($destination));
        $movedPath = $upload->fullPath();

        // destroyed AFTER a successful move - the file now belongs to the
        // caller and must survive destruction
        unset($upload);

        $this->assertFileExists($movedPath);
    }

    public function testMoveCollisionKeepsPointingAtTheTemporaryFile(): void
    {
        $destination = $this->workingDir . '/dest';
        mkdir($destination, 0777, true);

        // occupy the destination name
        file_put_contents($destination . '/example.txt', 'existing content');

        $upload = new UploadObject($this->config(), $this->fileData());
        $tempPath = $upload->fullPath();

        $this->assertFalse($upload->move($destination));

        // the object still points at OUR temp file - never at the foreign
        // destination file
        $this->assertSame($tempPath, $upload->fullPath());
        $this->assertFalse($upload->isValid());
        $this->assertSame(UploadObject::ERR_ALREADY_EXISTS, 199);
        $this->assertSame($this->config()['error map'][199], $upload->errorMessage());

        // delete() removes the temp file - the existing destination file is untouched
        $this->assertTrue($upload->delete());
        $this->assertSame('existing content', file_get_contents($destination . '/example.txt'));
    }

    public function testMoveAutoNumberResolvesCollision(): void
    {
        $destination = $this->workingDir . '/dest';
        mkdir($destination, 0777, true);

        file_put_contents($destination . '/example.txt', 'existing');

        $upload = new UploadObject($this->config(), $this->fileData());

        $this->assertTrue($upload->move($destination, null, true));
        $this->assertSame($destination . '/example 1.txt', $upload->fullPath());
        $this->assertFileExists($upload->fullPath());
    }

    public function testConstructorReportsAllMissingKeysAtOnce(): void
    {
        try {
            new UploadObject($this->config(), ['name' => 'x.txt', 'error' => 0]);

            $this->fail('expected FilesFormatError');
        } catch (FilesFormatError $e) {
            // every missing key in one message, not just the first
            $this->assertStringContainsString('full_path', $e->getMessage());
            $this->assertStringContainsString('type', $e->getMessage());
            $this->assertStringContainsString('tmp_name', $e->getMessage());
            $this->assertStringContainsString('size', $e->getMessage());
        }
    }

    public function testUnknownErrorNumberThrows(): void
    {
        $this->expectException(FilesFormatError::class);

        new UploadObject($this->config(), $this->fileData('x.txt', 'x', 99));
    }

    public function testIsOneOfWithoutMimesConfigOnlyMatchesExactTypes(): void
    {
        // the default config ships an empty mimes map - extension matching
        // simply finds nothing, no warnings
        $upload = new UploadObject($this->config(), $this->fileData());

        $this->assertTrue($upload->isOneOf('text/plain'));
        $this->assertFalse($upload->isOneOf('txt'));
    }

    public function testDeleteThrowsWhenFileAlreadyGone(): void
    {
        $upload = new UploadObject($this->config(), $this->fileData());

        $this->assertTrue($upload->delete());

        $this->expectException(FileNotFound::class);

        $upload->delete();
    }
}
