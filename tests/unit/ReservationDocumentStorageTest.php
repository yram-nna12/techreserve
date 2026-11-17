<?php

use App\Services\ReservationDocumentStorage;
use PHPUnit\Framework\TestCase;

final class ReservationDocumentStorageTest extends TestCase
{
    public function testAcceptsActualJpegAndPngContent(): void
    {
        $samples = [
            ['jpg', '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAGf/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPwF//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPwF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQAGPwJ//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPyF//9oADAMBAAIAAwAAABAf/8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPxB//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPxB//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxB//9k='],
            ['png', 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jYykAAAAASUVORK5CYII='],
        ];

        foreach ($samples as [$extension, $encoded]) {
            $path = tempnam(sys_get_temp_dir(), 'reservation-form-');
            $this->assertNotFalse($path);
            $contents = base64_decode($encoded, true);
            $this->assertNotFalse($contents);
            file_put_contents($path, $contents);
            try {
                $metadata = (new ReservationDocumentStorage(sys_get_temp_dir(), 1024))->inspectFile(
                    $path,
                    'signed-form.' . $extension,
                    filesize($path)
                );
                $this->assertSame($extension === 'jpg' ? 'image/jpeg' : 'image/png', $metadata['mime_type']);
                $this->assertSame($extension, $metadata['extension']);
            } finally {
                unlink($path);
            }
        }
    }

    public function testSniffsActualPdfTypeAndEnforcesSizeAndExtension(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'reservation-form-');
        $this->assertNotFalse($path);
        file_put_contents($path, "%PDF-1.4\n% synthetic test form\n");
        try {
            $storage = new ReservationDocumentStorage(sys_get_temp_dir(), 1024);
            $metadata = $storage->inspectFile($path, 'signed-form.pdf', filesize($path));
            $this->assertSame('application/pdf', $metadata['mime_type']);
            $this->assertSame('pdf', $metadata['extension']);
            $this->assertSame(hash_file('sha256', $path), $metadata['sha256']);

            try {
                $storage->inspectFile($path, 'signed-form.png', filesize($path));
                $this->fail('A mismatched filename extension was accepted.');
            } catch (DomainException) {
                $this->assertTrue(true);
            }

            try {
                $storage->inspectFile($path, 'signed-form.pdf', filesize($path) + 1);
                $this->fail('An upload with inconsistent size metadata was accepted.');
            } catch (DomainException) {
                $this->assertTrue(true);
            }
        } finally {
            unlink($path);
        }
    }

    public function testRejectsUnsupportedActualTypesAndOversizeFiles(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'reservation-form-');
        $this->assertNotFalse($path);
        file_put_contents($path, str_repeat('text', 300));
        try {
            $smallLimit = new ReservationDocumentStorage(sys_get_temp_dir(), 100);
            $this->expectException(DomainException::class);
            $smallLimit->inspectFile($path, 'form.pdf', filesize($path));
        } finally {
            unlink($path);
        }
    }
}
