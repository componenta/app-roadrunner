<?php

declare(strict_types=1);

namespace Componenta\App\RoadRunner\Tests\Fixture;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

/** Emits a single 8 KiB chunk, then fails while reading the remaining data. */
final class FailingReadStream implements StreamInterface
{
    private int $position = 0;

    public function __construct(private readonly ?int $size) {}

    public function __toString(): string { return ''; }
    public function close(): void {}
    public function detach() { return null; }
    public function getSize(): ?int { return $this->size; }
    public function tell(): int { return $this->position; }
    public function eof(): bool { return false; }
    public function isSeekable(): bool { return true; }
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        if ($offset !== 0 || $whence !== SEEK_SET) {
            throw new RuntimeException('Fixture only supports rewind.');
        }
        $this->position = 0;
    }
    public function rewind(): void { $this->seek(0); }
    public function isWritable(): bool { return false; }
    public function write(string $string): int { throw new RuntimeException('not writable'); }
    public function isReadable(): bool { return true; }
    public function read(int $length): string
    {
        if ($this->position >= 8192) {
            throw new RuntimeException('intentional stream read failure');
        }
        $length = min($length, 8192 - $this->position);
        $this->position += $length;

        return str_repeat('x', $length);
    }
    public function getContents(): string { throw new RuntimeException('intentional stream read failure'); }
    public function getMetadata(?string $key = null): mixed { return $key === null ? [] : null; }
}
