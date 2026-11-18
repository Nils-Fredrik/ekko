<?php

namespace App\Storage;

use Google\Cloud\Storage\StorageClient;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToWriteFile;
use Throwable;

/**
 * Report logos and PDFs on the ekko-reports bucket (docs/spec.md 11.2).
 * Web uploads the logo and the task job reads it while writing the PDF, so both use this bucket.
 */
final class GcsReportsAdapter implements FilesystemAdapter
{
    public function __construct(private readonly StorageClient $storage, private readonly string $bucket) {}

    public function fileExists(string $path): bool
    {
        return $this->storage->bucket($this->bucket)->object($this->name($path))->exists();
    }

    public function directoryExists(string $path): bool
    {
        return false;
    }

    public function write(string $path, string $contents, Config $config): void
    {
        try {
            $this->storage->bucket($this->bucket)->upload($contents, ['name' => $this->name($path)]);
        } catch (Throwable $e) {
            throw UnableToWriteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        if (! is_resource($contents)) {
            throw UnableToWriteFile::atLocation($path, 'The upload was not a stream.');
        }
        $body = stream_get_contents($contents);
        if (! is_string($body)) {
            throw UnableToWriteFile::atLocation($path, 'The stream could not be read.');
        }
        $this->write($path, $body, $config);
    }

    public function read(string $path): string
    {
        try {
            return $this->storage->bucket($this->bucket)->object($this->name($path))->downloadAsString();
        } catch (Throwable $e) {
            throw UnableToReadFile::fromLocation($path, $e->getMessage(), $e);
        }
    }

    public function readStream(string $path)
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw UnableToReadFile::fromLocation($path, 'A temporary stream could not be opened.');
        }
        fwrite($stream, $this->read($path));
        rewind($stream);

        return $stream;
    }

    public function delete(string $path): void
    {
        $object = $this->storage->bucket($this->bucket)->object($this->name($path));
        if ($object->exists()) {
            $object->delete();
        }
    }

    public function deleteDirectory(string $path): void
    {
        $prefix = rtrim($this->name($path), '/').'/';
        foreach ($this->storage->bucket($this->bucket)->objects(['prefix' => $prefix]) as $object) {
            $object->delete();
        }
    }

    public function createDirectory(string $path, Config $config): void
    {
        // Object names already contain their prefix.
    }

    public function setVisibility(string $path, string $visibility): void
    {
        // Report objects stay private to the service accounts.
    }

    public function visibility(string $path): FileAttributes
    {
        return new FileAttributes($path);
    }

    public function mimeType(string $path): FileAttributes
    {
        return new FileAttributes($path);
    }

    public function lastModified(string $path): FileAttributes
    {
        return new FileAttributes($path);
    }

    public function fileSize(string $path): FileAttributes
    {
        return new FileAttributes($path);
    }

    public function listContents(string $path, bool $deep): iterable
    {
        return [];
    }

    public function move(string $source, string $destination, Config $config): void
    {
        try {
            $this->copy($source, $destination, $config);
            $this->delete($source);
        } catch (Throwable $e) {
            throw UnableToMoveFile::fromLocationTo($source, $destination, $e);
        }
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        try {
            $this->write($destination, $this->read($source), $config);
        } catch (Throwable $e) {
            throw UnableToCopyFile::fromLocationTo($source, $destination, $e);
        }
    }

    private function name(string $path): string
    {
        $name = ltrim($path, '/');
        if ($name === '' || str_contains($name, '..')) {
            throw UnableToReadFile::fromLocation($path, 'The report path is not allowed.');
        }

        return $name;
    }
}
