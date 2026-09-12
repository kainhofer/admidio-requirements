<?php
namespace AdmidioPlugin\Requirements\Tests\Unit;

use AdmidioPlugin\Requirements\Entity\ActivityFile;
use AdmidioPlugin\Requirements\Service\ActivityFileService;
use AdmidioPlugin\Requirements\Tests\Unit\Support\RequirementsUnitTestCase;

require_once __DIR__ . '/Support/RequirementsUnitTestCase.php';

/**
 * The two decisions the certificate upload makes before it ever touches a database: which types are
 * accepted, and what a `$_FILES` entry actually contains.
 *
 * Both are pure, and both are the kind of thing that fails quietly. An accepted type that loses its
 * extension would store a file nothing can name; a multi-file field read as if it were a single one
 * would attach the first file and drop the rest without saying so.
 */
final class ActivityFileVocabularyTest extends RequirementsUnitTestCase
{
    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function acceptedTypes(): array
    {
        return array(
            'a PDF' => array('application/pdf', 'pdf'),
            'a JPEG' => array('image/jpeg', 'jpg'),
            'a PNG' => array('image/png', 'png'),
            'upper case' => array('APPLICATION/PDF', 'pdf'),
            'padded' => array('  image/png  ', 'png')
        );
    }

    /**
     * @dataProvider acceptedTypes
     */
    public function testAnAcceptedTypeHasAnExtension(string $mimeType, string $extension): void
    {
        $this->assertSame($extension, ActivityFile::extensionForMimeType($mimeType));
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function rejectedTypes(): array
    {
        return array(
            'a PHP script' => array('application/x-httpd-php'),
            'an SVG, which a browser would execute' => array('image/svg+xml'),
            'HTML' => array('text/html'),
            'an unknown type' => array('application/octet-stream'),
            'nothing at all' => array('')
        );
    }

    /**
     * A type with no extension is a type the upload refuses. The allow-list is the whole check, so
     * anything that is not on it has to come back empty rather than fall through to a default.
     *
     * @dataProvider rejectedTypes
     */
    public function testARejectedTypeHasNoExtension(string $mimeType): void
    {
        $this->assertSame('', ActivityFile::extensionForMimeType($mimeType));
    }

    public function testEveryAcceptedTypeMapsToItsOwnExtension(): void
    {
        $extensions = array_values(ActivityFile::mimeTypes());

        $this->assertSame($extensions, array_unique($extensions), 'two types sharing an extension');
        $this->assertNotContains('', $extensions);
    }

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function configuredTypeTokens(): array
    {
        return array(
            'a plain extension' => array('pdf', 'pdf'),
            'padded and upper case' => array('  PNG ', 'png'),
            'written with a dot' => array('.pdf', 'pdf'),
            'the other spelling of jpg' => array('jpeg', 'jpg'),
            'a type the plugin cannot store' => array('docx', ''),
            'a MIME type rather than an extension' => array('application/pdf', ''),
            'nothing' => array('', '')
        );
    }

    /**
     * The setting names extensions, and an administrator writing "jpeg" means the same format as
     * one writing "jpg" - resolving only one of the two would switch off a format they believed
     * they had allowed, and they would find out from a member who could not upload.
     *
     * @dataProvider configuredTypeTokens
     */
    public function testAConfiguredTokenResolvesToTheTypeItNames(string $token, string $expected): void
    {
        $this->assertSame($expected, ActivityFile::canonicalExtension($token));
    }

    /**
     * The alias table may only name types the plugin can actually store, or the setting would
     * appear to accept something no upload can produce.
     */
    public function testEveryAliasPointsAtASupportedType(): void
    {
        foreach (ActivityFile::EXTENSION_ALIASES as $alias => $extension) {
            $this->assertContains(
                $extension,
                ActivityFile::mimeTypes(),
                'the alias "' . $alias . '" names "' . $extension . '", which cannot be stored'
            );
        }
    }

    public function testASingleFileFieldBecomesOneUpload(): void
    {
        $uploads = ActivityFileService::uploadsFrom(array(
            'name' => 'certificate.pdf',
            'tmp_name' => '/tmp/php123',
            'size' => 4096,
            'error' => UPLOAD_ERR_OK
        ));

        $this->assertCount(1, $uploads);
        $this->assertSame('certificate.pdf', $uploads[0]['name']);
        $this->assertSame(4096, $uploads[0]['size']);
    }

    /**
     * PHP transposes a multi-file field into parallel arrays, which is the shape nothing wants.
     */
    public function testAMultiFileFieldBecomesOneUploadPerFile(): void
    {
        $uploads = ActivityFileService::uploadsFrom(array(
            'name' => array('first.pdf', 'second.png'),
            'tmp_name' => array('/tmp/php1', '/tmp/php2'),
            'size' => array(10, 20),
            'error' => array(UPLOAD_ERR_OK, UPLOAD_ERR_OK)
        ));

        $this->assertSame(array('first.pdf', 'second.png'), array_column($uploads, 'name'));
        $this->assertSame(array('/tmp/php1', '/tmp/php2'), array_column($uploads, 'tmp_name'));
    }

    /**
     * A form submitted with no file chosen sends an empty slot, and a multi-upload widget sends one
     * per row the member never filled in. None of them is an upload.
     */
    public function testEmptySlotsAreNotUploads(): void
    {
        $uploads = ActivityFileService::uploadsFrom(array(
            'name' => array('', 'real.pdf', ''),
            'tmp_name' => array('', '/tmp/php2', ''),
            'size' => array(0, 20, 0),
            'error' => array(UPLOAD_ERR_NO_FILE, UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE)
        ));

        $this->assertCount(1, $uploads);
        $this->assertSame('real.pdf', $uploads[0]['name']);
    }

    /**
     * A file too large for PHP arrives with an error and no temporary file. It has to survive as an
     * upload, because dropping it here would turn a refusal the member should see into silence.
     */
    public function testAFailedUploadIsStillReported(): void
    {
        $uploads = ActivityFileService::uploadsFrom(array(
            'name' => array('huge.pdf'),
            'tmp_name' => array(''),
            'size' => array(0),
            'error' => array(UPLOAD_ERR_INI_SIZE)
        ));

        $this->assertCount(1, $uploads);
        $this->assertSame(UPLOAD_ERR_INI_SIZE, $uploads[0]['error']);
    }

    public function testAFieldWithNoNameIsNoUploadAtAll(): void
    {
        $this->assertSame(array(), ActivityFileService::uploadsFrom(array()));
    }
}
