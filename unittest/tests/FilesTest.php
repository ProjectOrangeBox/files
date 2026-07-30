<?php

declare(strict_types=1);

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

    private function config(): array
    {
        $config = require __DIR__ . '/../../src/config/files.php';
        $config['workingDirectory'] = $this->workingDir;
        $config['auto cleanup seconds'] = 0;

        return $config;
    }

    private function tmpFile(string $content): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'orange_files_');
        file_put_contents($tmp, $content);

        return $tmp;
    }

    private function singleFileData(string $name = 'single.txt', string $content = 'abc'): array
    {
        $tmp = $this->tmpFile($content);

        return [
            'name' => $name,
            'full_path' => $tmp,
            'type' => 'text/plain',
            'tmp_name' => $tmp,
            'error' => 0,
            'size' => strlen($content),
        ];
    }

    public function testGetSingleFileReturnsUploadObject(): void
    {
        $fileData = $this->singleFileData();

        $input = $this->createStub(InputInterface::class);
        $input->method('file')->willReturnMap([
            ['upload', UNDEFINED, $fileData],
        ]);

        $files = Files::newInstance($this->config(), $input);

        $uploadObjects = $files->get('upload');

        $this->assertArrayHasKey('upload', $uploadObjects);
        $this->assertInstanceOf(UploadObject::class, $uploadObjects['upload']);

        // cleanup object so destructor does not fail
        unset($uploadObjects);
    }

    public function testGetMultipleFilesReturnsMultipleUploadObjects(): void
    {
        $tmpFile1 = $this->tmpFile('x');
        $tmpFile2 = $this->tmpFile('y');

        $filesGrouped = [
            'name' => ['a.txt', 'b.txt'],
            'full_path' => [$tmpFile1, $tmpFile2],
            'type' => ['text/plain', 'text/plain'],
            'tmp_name' => [$tmpFile1, $tmpFile2],
            'error' => [0, 0],
            'size' => [1, 1],
        ];

        $input = $this->createStub(InputInterface::class);
        $input->method('file')->willReturnMap([
            [null, UNDEFINED, ['files' => $filesGrouped]],
            ['files', UNDEFINED, $filesGrouped],
        ]);

        $files = Files::newInstance($this->config(), $input);

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
        $input = $this->createStub(InputInterface::class);
        $input->method('file')->willReturnMap([
            [null, UNDEFINED, UNDEFINED],
        ]);

        $files = Files::newInstance($this->config(), $input);

        $this->expectException(NoFilesFound::class);

        $files->get();
    }

    public function testRepeatedGetReturnsTheSameMemoizedObjects(): void
    {
        $fileData = $this->singleFileData();

        $input = $this->createStub(InputInterface::class);
        $input->method('file')->willReturnMap([
            ['upload', UNDEFINED, $fileData],
            [null, UNDEFINED, ['upload' => $fileData]],
        ]);

        $files = Files::newInstance($this->config(), $input);

        $first = $files->get('upload');

        // processing consumed the PHP temp file - before memoization a second
        // call re-processed and blew up with CouldNotLocateFile
        $second = $files->get('upload');
        $all = $files->get();

        $this->assertSame($first['upload'], $second['upload']);
        $this->assertSame($first['upload'], $all['upload']);
    }

    public function testGetOneReturnsTheObjectDirectly(): void
    {
        $fileData = $this->singleFileData();

        $input = $this->createStub(InputInterface::class);
        $input->method('file')->willReturnMap([
            ['upload', UNDEFINED, $fileData],
        ]);

        $files = Files::newInstance($this->config(), $input);

        $upload = $files->getOne('upload');

        $this->assertInstanceOf(UploadObject::class, $upload);
        $this->assertSame('single.txt', $upload->userFilename());
    }

    public function testGetFieldReturnsOnlyThatFieldsObjects(): void
    {
        $avatar = $this->singleFileData('avatar.txt');
        $resume = $this->singleFileData('resume.txt');

        $input = $this->createStub(InputInterface::class);
        $input->method('file')->willReturnMap([
            ['avatar', UNDEFINED, $avatar],
            ['resume', UNDEFINED, $resume],
        ]);

        $files = Files::newInstance($this->config(), $input);

        $files->get('avatar');

        $resumeObjects = $files->get('resume');

        // only resume's entry - not everything processed so far
        $this->assertCount(1, $resumeObjects);
        $this->assertArrayHasKey('resume', $resumeObjects);
    }

    public function testHas(): void
    {
        $fileData = $this->singleFileData();

        $input = $this->createStub(InputInterface::class);
        $input->method('file')->willReturnMap([
            ['upload', UNDEFINED, $fileData],
            ['missing', UNDEFINED, UNDEFINED],
        ]);

        $files = Files::newInstance($this->config(), $input);

        $this->assertTrue($files->has('upload'));
        $this->assertFalse($files->has('missing'));

        // still true after processing consumed the raw input
        $files->get('upload');
        $this->assertTrue($files->has('upload'));
    }

    public function testGetUnknownFieldThrows(): void
    {
        $input = $this->createStub(InputInterface::class);
        $input->method('file')->willReturnMap([
            ['nope', UNDEFINED, UNDEFINED],
        ]);

        $files = Files::newInstance($this->config(), $input);

        $this->expectException(NoFilesFound::class);

        $files->get('nope');
    }
}
