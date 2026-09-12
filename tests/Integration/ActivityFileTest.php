<?php
namespace AdmidioPlugin\Requirements\Tests\Integration;

use Admidio\Hooks\Hooks;
use Admidio\Infrastructure\Utils\FileSystemUtils;
use AdmidioPlugin\Requirements\Database\RequirementsTables;
use AdmidioPlugin\Requirements\Entity\Activity;
use AdmidioPlugin\Requirements\Entity\ActivityFile;
use AdmidioPlugin\Requirements\RequirementsPlugin;
use AdmidioPlugin\Requirements\Service\ActivityFileService;
use AdmidioPlugin\Requirements\Service\ActivityService;
use AdmidioPlugin\Requirements\Tests\Support\RequirementsDatabaseTestCase;

require_once dirname(__DIR__) . '/Support/RequirementsDatabaseTestCase.php';

/**
 * The certificate files of an activity.
 *
 * A file lives in two places - a row and a file on disk - and almost everything that can go wrong
 * here is the two disagreeing, or the wrong person reaching one. So the assertions are mostly about
 * pairs: the row **and** the file appear, the row **and** the file go, a refusal leaves neither.
 *
 * The permission cases are the point of the whole design. The evidence is not in the Documents
 * module precisely because that module answers "which roles may see this folder", and the question
 * here is "whose activity is this" - so the tests below ask it as a member, not only as the
 * administrator the test case logs in as.
 */
final class ActivityFileTest extends RequirementsDatabaseTestCase
{
    /** A minimal but genuine PDF, so the MIME sniff has something real to recognise. */
    private const PDF = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

    /** @var array<int,string> paths written by a test, removed afterwards */
    private array $temporaryFiles = array();

    protected function setUp(): void
    {
        parent::setUp();

        $this->enablePluginForCurrentOrganization(array(
            ActivityFileService::SETTING_ENABLED => '1',
            ActivityFileService::SETTING_MAX_COUNT => '3',
            ActivityFileService::SETTING_MAX_SIZE => '10',
            ActivityFileService::SETTING_TOTAL_QUOTA => '0'
        ));
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->temporaryFiles = array();

        $this->removeStoredFiles();

        parent::tearDown();
    }

    public function testAnAttachedFileProducesARowAndAFile(): void
    {
        $member = $this->createMember('req_file_add');
        $activity = $this->activityFor($member['usr_id']);

        $file = $this->attach($activity, 'Course confirmation.pdf');

        $this->assertSame(1, $this->countFiles());
        $this->assertSame('application/pdf', (string) $file->getValue('raf_mime_type'));
        $this->assertSame(strlen(self::PDF), (int) $file->getValue('raf_size'));
        $this->assertFileExists($this->service()->pathFor($file));
    }

    /**
     * The name the browser sent never reaches the disk. It is kept for the download header only,
     * so a name that is a path, or that carries a second extension, cannot become either.
     */
    public function testTheStoredFileIsNamedAfterTheUuidAndNotTheUpload(): void
    {
        $member = $this->createMember('req_file_name');
        $activity = $this->activityFor($member['usr_id']);

        $file = $this->attach($activity, '../../evil.php.pdf');

        $stored = basename($this->service()->pathFor($file));
        $this->assertSame($file->getValue('raf_uuid') . '.pdf', $stored);
        // The display name is kept, but reduced to something that is only ever printed.
        $this->assertSame('evil.php.pdf', (string) $file->getValue('raf_name'));
    }

    /**
     * The type is decided by what is in the file, not by what the upload claims. This is the check
     * that stops a script from being stored under an extension that a misconfigured server serves.
     */
    public function testTheSniffedTypeDecidesAndNotTheName(): void
    {
        $member = $this->createMember('req_file_sniff');
        $activity = $this->activityFor($member['usr_id']);

        $path = $this->writeTemporary("<?php echo 'pwned';", 'php');

        $this->assertRefusedWith('PLG_REQUIREMENTS_FILES_TYPE_REJECTED', function () use ($activity, $path): void {
            // Named like a certificate, and it is not one.
            $this->service()->add($activity, $this->upload($path, 'certificate.pdf'));
        }, array('text/x-php', implode(', ', ActivityFile::mimeTypes())));

        $this->assertSame(0, $this->countFiles());
    }

    public function testTheFileCountPerActivityIsALimit(): void
    {
        $member = $this->createMember('req_file_count');
        $activity = $this->activityFor($member['usr_id']);

        for ($number = 1; $number <= 3; ++$number) {
            $this->attach($activity, 'certificate' . $number . '.pdf');
        }

        $this->assertRefusedWith('PLG_REQUIREMENTS_FILES_TOO_MANY', function () use ($activity): void {
            $this->attach($activity, 'one-too-many.pdf');
        }, array(3));

        $this->assertSame(3, $this->countFiles());
    }

    /**
     * The quota is the sum over the organization, not a per-file limit, so it has to be exceeded by
     * a second file that would have been perfectly acceptable on its own.
     */
    public function testTheOrganizationQuotaCountsTheSumAndNotTheFile(): void
    {
        $this->enablePluginForCurrentOrganization(array(
            ActivityFileService::SETTING_ENABLED => '1',
            ActivityFileService::SETTING_MAX_COUNT => '10',
            ActivityFileService::SETTING_MAX_SIZE => '10',
            ActivityFileService::SETTING_TOTAL_QUOTA => '1'
        ));

        $member = $this->createMember('req_file_quota');
        $activity = $this->activityFor($member['usr_id']);

        // Seven tenths of the quota each: the first fits, the second does not, and neither is
        // anywhere near the per-file limit of 10 MiB.
        $big = str_pad(self::PDF, (int) (0.7 * 1048576), ' ');
        $this->service()->add($activity, $this->upload($this->writeTemporary($big, 'pdf'), 'first.pdf'));
        $this->assertSame(1, $this->countFiles());

        $second = $this->writeTemporary($big, 'pdf');
        $this->assertRefusedWith(
            'PLG_REQUIREMENTS_FILES_QUOTA_EXCEEDED',
            function () use ($activity, $second): void {
                $this->service()->add($activity, $this->upload($second, 'second.pdf'));
            },
            array(FileSystemUtils::getHumanReadableBytes(1048576))
        );

        $this->assertSame(1, $this->countFiles(), 'the refused file left no row');
    }

    public function testAMemberMayAttachToTheirOwnActivityAndNotToAnothers(): void
    {
        $mine = $this->createMember('req_file_mine');
        $theirs = $this->createMember('req_file_theirs');
        $myActivity = $this->activityFor($mine['usr_id']);
        $theirActivity = $this->activityFor($theirs['usr_id']);

        $this->asMember($mine['usr_id'], function () use ($myActivity, $theirActivity): void {
            $this->attach($myActivity, 'my-certificate.pdf');

            $this->assertRefusedWith('SYS_NO_RIGHTS', function () use ($theirActivity): void {
                $this->attach($theirActivity, 'not-mine.pdf');
            });
        });

        $this->assertSame(1, $this->countFiles());
    }

    /**
     * The download is where a leak would actually happen, so it is asked directly: a member holding
     * the UUID of somebody else's certificate is refused before the file is opened.
     */
    public function testAMemberCannotDownloadAnotherMembersCertificate(): void
    {
        $owner = $this->createMember('req_file_owner');
        $other = $this->createMember('req_file_other');
        $activity = $this->activityFor($owner['usr_id']);
        $file = $this->attach($activity, 'private.pdf');

        $this->asMember($other['usr_id'], function () use ($file): void {
            $reloaded = new ActivityFile($this->getDatabase(), (int) $file->getValue('raf_id'));

            $this->assertRefusedWith('SYS_NO_RIGHTS', function () use ($reloaded): void {
                $this->service()->streamDownload($reloaded);
            });
        });
    }

    /**
     * Under the until_submitted policy a submitted activity is visible but no longer editable, and
     * its evidence follows the same rule: the member may still read it, not change it.
     */
    public function testASubmittedActivityTakesNoMoreFilesFromItsOwner(): void
    {
        $this->enablePluginForCurrentOrganization(array(
            ActivityFileService::SETTING_ENABLED => '1',
            ActivityFileService::SETTING_MAX_COUNT => '3',
            ActivityFileService::SETTING_MAX_SIZE => '10',
            ActivityFileService::SETTING_TOTAL_QUOTA => '0',
            RequirementsPlugin::SETTING_ACTIVITY_OWNER_EDIT_POLICY =>
                RequirementsPlugin::EDIT_POLICY_UNTIL_SUBMITTED
        ));

        $member = $this->createMember('req_file_submitted');
        $activity = $this->activityFor($member['usr_id']);
        (new ActivityService($this->getDatabase()))->submit($activity);

        $this->asMember($member['usr_id'], function () use ($activity): void {
            $reloaded = new Activity($this->getDatabase(), (int) $activity->getValue('rqa_id'));

            $this->assertRefusedWith('SYS_NO_RIGHTS', function () use ($reloaded): void {
                $this->attach($reloaded, 'too-late.pdf');
            });
        });
    }

    public function testDeletingAFileRemovesBothTheRowAndTheFile(): void
    {
        $member = $this->createMember('req_file_delete');
        $activity = $this->activityFor($member['usr_id']);
        $file = $this->attach($activity, 'certificate.pdf');
        $path = $this->service()->pathFor($file);

        $this->service()->delete($file);

        $this->assertSame(0, $this->countFiles());
        $this->assertFileDoesNotExist($path);
    }

    /**
     * The rows go with the activity through the foreign key. The files on disk do not - nothing in
     * the database can unlink a file - which is what `files-gc` exists for, and what this asserts
     * rather than pretends otherwise.
     */
    public function testDeletingTheActivityCascadesToTheRowsAndLeavesTheFilesToTheSweep(): void
    {
        $member = $this->createMember('req_file_cascade');
        $activity = $this->activityFor($member['usr_id']);
        $file = $this->attach($activity, 'certificate.pdf');
        $path = $this->service()->pathFor($file);

        (new ActivityService($this->getDatabase()))->delete($activity);

        $this->assertSame(0, $this->countFiles(), 'the rows go with the activity');
        $this->assertFileExists($path, 'the file outlives it until the sweep runs');

        $swept = $this->service()->collectGarbage(false);

        $this->assertSame(array(basename($path)), $swept['orphanFiles']);
        $this->assertFileDoesNotExist($path);
    }

    /**
     * The other half of the sweep: a row whose file is gone. It is a broken download link and
     * nothing else would ever find it.
     */
    public function testTheSweepRemovesARowWhoseFileIsGone(): void
    {
        $member = $this->createMember('req_file_gc_row');
        $activity = $this->activityFor($member['usr_id']);
        $file = $this->attach($activity, 'certificate.pdf');
        $uuid = (string) $file->getValue('raf_uuid');

        unlink($this->service()->pathFor($file));

        $swept = $this->service()->collectGarbage(false);

        $this->assertSame(array($uuid), $swept['missingFiles']);
        $this->assertSame(0, $this->countFiles());
    }

    public function testTheSweepReportsWithoutDeletingUnlessAsked(): void
    {
        $member = $this->createMember('req_file_gc_dry');
        $activity = $this->activityFor($member['usr_id']);
        $file = $this->attach($activity, 'certificate.pdf');
        $path = $this->service()->pathFor($file);
        (new ActivityService($this->getDatabase()))->delete($activity);

        $reported = $this->service()->collectGarbage(true);

        $this->assertSame(array(basename($path)), $reported['orphanFiles']);
        $this->assertFileExists($path, 'a report is not a deletion');
    }

    public function testASoundActivityIsLeftAloneByTheSweep(): void
    {
        $member = $this->createMember('req_file_gc_clean');
        $activity = $this->activityFor($member['usr_id']);
        $file = $this->attach($activity, 'certificate.pdf');

        $swept = $this->service()->collectGarbage(false);

        $this->assertSame(array(), $swept['missingFiles']);
        $this->assertSame(array(), $swept['orphanFiles']);
        $this->assertSame(1, $this->countFiles());
        $this->assertFileExists($this->service()->pathFor($file));
    }

    public function testNothingIsAttachedWhileTheFeatureIsSwitchedOff(): void
    {
        $this->enablePluginForCurrentOrganization(array(ActivityFileService::SETTING_ENABLED => '0'));

        $member = $this->createMember('req_file_off');
        $activity = $this->activityFor($member['usr_id']);

        $this->assertRefusedWith('PLG_REQUIREMENTS_FILES_DISABLED', function () use ($activity): void {
            $this->attach($activity, 'certificate.pdf');
        });
        $this->assertSame(0, $this->countFiles());
    }

    /**
     * The stored path is built only from values this code produced, and it is checked against the
     * plugin's own directory anyway - a path assembled from trusted parts stays trusted only until
     * somebody adds a part.
     */
    public function testTheStoredPathStaysInsideThePluginDirectory(): void
    {
        $member = $this->createMember('req_file_path');
        $activity = $this->activityFor($member['usr_id']);
        $file = $this->attach($activity, 'certificate.pdf');

        $path = $this->service()->pathFor($file);

        // DIRECTORY_SEPARATOR, not '/': getNormalizedPath() joins with it, so an assertion written
        // against a forward slash passes on Linux and fails on Windows for a perfectly good path.
        $this->assertStringStartsWith(
            $this->service()->organizationDirectory() . DIRECTORY_SEPARATOR,
            $path
        );
        $this->assertStringNotContainsString('..', $path);
    }

    /**
     * The setting narrows what the plugin can store. It cannot widen it: a type the plugin has no
     * extension for is ignored rather than accepted, so a typo in the setting cannot let an
     * unstorable file through.
     */
    public function testTheAllowedTypesSettingNarrowsWhatIsAccepted(): void
    {
        $this->enablePluginForCurrentOrganization(array(
            ActivityFileService::SETTING_ENABLED => '1',
            ActivityFileService::SETTING_MAX_COUNT => '5',
            ActivityFileService::SETTING_MAX_SIZE => '10',
            ActivityFileService::SETTING_TOTAL_QUOTA => '0',
            ActivityFileService::SETTING_ALLOWED_TYPES => 'png,docx'
        ));

        $this->assertSame(array('image/png' => 'png'), ActivityFileService::allowedMimeTypes());

        $member = $this->createMember('req_file_types');
        $activity = $this->activityFor($member['usr_id']);

        $this->assertRefusedWith('PLG_REQUIREMENTS_FILES_TYPE_REJECTED', function () use ($activity): void {
            $this->attach($activity, 'certificate.pdf');
        }, array('application/pdf', 'png'));

        $this->assertSame(0, $this->countFiles());
    }

    public function testAnEmptyAllowedTypesSettingMeansEverySupportedType(): void
    {
        $this->enablePluginForCurrentOrganization(array(
            ActivityFileService::SETTING_ENABLED => '1',
            ActivityFileService::SETTING_MAX_COUNT => '5',
            ActivityFileService::SETTING_MAX_SIZE => '10',
            ActivityFileService::SETTING_TOTAL_QUOTA => '0',
            ActivityFileService::SETTING_ALLOWED_TYPES => ''
        ));

        $this->assertSame(ActivityFile::mimeTypes(), ActivityFileService::allowedMimeTypes());
    }

    /**
     * The extension point a virus scanner plugs into. Nothing in Admidio scans an upload today, so
     * what is asserted here is that the hook is dispatched at all, with the temporary file still in
     * place, and that a refusal stops the upload rather than being logged and ignored.
     */
    public function testAListenerMayRefuseAnUpload(): void
    {
        $member = $this->createMember('req_file_scan');
        $activity = $this->activityFor($member['usr_id']);

        $seen = array();
        // Registered and removed by the callback itself rather than by an explicit id: passing an
        // id to removeFilter() hits a TypeError in Hooks::remove(), which offers the id as the
        // documented way to remove a registration but then hands the string to callableId(), whose
        // parameter is typed callable. Reported separately - the workaround belongs here, not a
        // change to core.
        $scanner = static function (
            string $rejection,
            string $path,
            string $mimeType,
            string $name
        ) use (&$seen): string {
            $seen = array('path' => $path, 'mimeType' => $mimeType, 'name' => $name);

            return 'the scanner said no';
        };
        Hooks::addFilter('requirements_file_upload_rejection', $scanner);

        try {
            $this->assertRefusedWith(
                'PLG_REQUIREMENTS_FILES_INSPECTION_REJECTED',
                function () use ($activity): void {
                    $this->attach($activity, 'certificate.pdf');
                },
                array('the scanner said no')
            );
        } finally {
            Hooks::removeFilter('requirements_file_upload_rejection', $scanner);
        }

        $this->assertSame('application/pdf', $seen['mimeType'] ?? '');
        $this->assertSame('certificate.pdf', $seen['name'] ?? '');
        $this->assertFileExists($seen['path'] ?? '', 'the listener sees the file while it still exists');
        $this->assertSame(0, $this->countFiles(), 'a refused file leaves no row');
    }

    // ---------------------------------------------------------------- helpers

    private function service(): ActivityFileService
    {
        return new ActivityFileService($this->getDatabase());
    }

    private function activityFor(int $userId): Activity
    {
        $activity = new Activity($this->getDatabase());
        (new ActivityService($this->getDatabase()))->save($activity, array(
            'rqa_title' => 'Course for ' . $userId,
            'rqa_begin_date' => '2026-03-01'
        ), $userId);

        return $activity;
    }

    private function attach(Activity $activity, string $name): ActivityFile
    {
        return $this->service()->add($activity, $this->upload($this->writeTemporary(self::PDF, 'pdf'), $name));
    }

    /**
     * @return array{name:string,tmp_name:string,size:int,error:int}
     */
    private function upload(string $path, string $name): array
    {
        return array(
            'name' => $name,
            'tmp_name' => $path,
            'size' => (int) filesize($path),
            'error' => UPLOAD_ERR_OK
        );
    }

    private function writeTemporary(string $content, string $extension): string
    {
        $path = sys_get_temp_dir() . '/req_file_' . uniqid('', true) . '.' . $extension;
        file_put_contents($path, $content);
        $this->temporaryFiles[] = $path;

        return $path;
    }

    private function countFiles(): int
    {
        return (int) $this->getDatabase()->queryPrepared(
            'SELECT COUNT(*) FROM ' . RequirementsTables::activityFiles(),
            array()
        )->fetchColumn();
    }

    /**
     * The stored files are outside the per-test transaction, so they have to be swept by hand -
     * a rollback takes the rows and leaves the disk exactly as the test left it.
     */
    private function removeStoredFiles(): void
    {
        $directory = $this->service()->organizationDirectory();
        if (!is_dir($directory)) {
            return;
        }

        foreach (glob($directory . '/*') ?: array() as $activityDirectory) {
            foreach (glob($activityDirectory . '/*') ?: array() as $file) {
                @unlink($file);
            }
            @rmdir($activityDirectory);
        }
        @rmdir($directory);
    }
}
