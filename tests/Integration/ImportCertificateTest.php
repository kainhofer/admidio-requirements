<?php
namespace AdmidioPlugin\Requirements\Tests\Integration;

use AdmidioPlugin\Requirements\Database\RequirementsTables;
use AdmidioPlugin\Requirements\Entity\Activity;
use AdmidioPlugin\Requirements\Entity\ActivityFile;
use AdmidioPlugin\Requirements\Service\ActivityExportService;
use AdmidioPlugin\Requirements\Service\ActivityFileService;
use AdmidioPlugin\Requirements\Service\ActivityService;
use AdmidioPlugin\Requirements\Service\ImportArchive;
use AdmidioPlugin\Requirements\Service\ImportService;
use AdmidioPlugin\Requirements\Tests\Support\RequirementsDatabaseTestCase;
use ZipArchive;

require_once dirname(__DIR__) . '/Support/RequirementsDatabaseTestCase.php';

/**
 * Certificates arriving with an import: a ZIP of the spreadsheet and the files its `certificates`
 * column names, unpacked safely, checked in the preview and attached by the import.
 */
final class ImportCertificateTest extends RequirementsDatabaseTestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

    /** @var array<int,string> */
    private array $temporaryFiles = array();

    /** @var array<int,string> unpacked archives to delete */
    private array $directories = array();

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
            @unlink($path);
        }
        foreach ($this->directories as $directory) {
            ImportArchive::remove($directory);
        }

        // The stored certificates are outside the test transaction.
        $storage = (new ActivityFileService($this->getDatabase()))->organizationDirectory();
        foreach (glob($storage . '/*/*') ?: array() as $file) {
            @unlink($file);
        }
        foreach (glob($storage . '/*') ?: array() as $directory) {
            @rmdir($directory);
        }

        parent::tearDown();
    }

    public function testTheCertificatesOfAnArchiveAreAttachedToTheirActivities(): void
    {
        $member = $this->createMember('req_imp_cert');
        $archive = $this->extract(array(
            'activities.csv' => "title,begin_date,certificates\n"
                . "Seminar A,2026-03-01,docs/A.pdf\n"
                . "Seminar B,2026-04-01,b.pdf; a.pdf\n",
            'docs/A.pdf' => self::PDF,
            'b.pdf' => self::PDF
        ));

        $result = $this->import($archive, $member['usr_id']);

        $this->assertSame(0, $result['summary']['error'], json_encode($result['rows']));
        $this->assertSame(array('A.pdf'), $result['rows'][0]['certificates']);
        $this->assertSame(array('b.pdf', 'A.pdf'), $result['rows'][1]['certificates'],
            'A file may be named by its name alone, and in any letter case.');
        $this->assertSame(array('A.pdf', 'A.pdf', 'b.pdf'), $this->storedNames());
    }

    /**
     * The archive the export writes is the archive the import takes: the activities and their
     * certificates come back as they were, the awkward file names included.
     */
    public function testAnExportWithCertificatesImportsAgainWithTheSameFiles(): void
    {
        $member = $this->createMember('req_exp_cert');
        $service = new ActivityService($this->getDatabase());
        $fileService = new ActivityFileService($this->getDatabase());
        $pdf = sys_get_temp_dir() . '/req_exp_cert_' . uniqid('', true) . '.pdf';
        file_put_contents($pdf, self::PDF);
        $this->temporaryFiles[] = $pdf;

        foreach (array(
            'Seminar; Day 1' => array('2026-06-25_ÖFdV; Life.pdf', 'same.pdf', 'same.pdf'),
            'Without certificate' => array()
        ) as $title => $names) {
            $activity = new Activity($this->getDatabase());
            $service->save($activity, array('rqa_title' => $title, 'rqa_begin_date' => '2026-06-25'), $member['usr_id']);
            foreach ($names as $name) {
                $fileService->add($activity, array(
                    'name' => $name, 'tmp_name' => $pdf, 'size' => strlen(self::PDF), 'error' => UPLOAD_ERR_OK
                ));
            }
        }

        $zipPath = sys_get_temp_dir() . '/req_exp_cert_' . uniqid('', true) . '.zip';
        $this->temporaryFiles[] = $zipPath;
        (new ActivityExportService($this->getDatabase()))->archive(array('userId' => $member['usr_id']), $zipPath);

        foreach ($service->findActivityRows(array('userId' => $member['usr_id']))['rows'] as $row) {
            $service->delete(new Activity($this->getDatabase(), (int) $row['rqa_id']));
        }
        $this->assertSame(array(), $this->storedNames());

        $archive = ImportArchive::extract($zipPath);
        $this->directories[] = $archive['directory'];
        // An administrator's export names the member in its user_uuid column.
        $result = $this->import($archive, null);

        $this->assertSame(0, $result['summary']['error'], json_encode($result['rows']));
        $this->assertSame(2, $result['summary']['create']);
        $this->assertSame(
            array('2026-06-25_ÖFdV, Life.pdf', 'same (2).pdf', 'same.pdf'),
            $this->storedNames(),
            'A semicolon would split the cell, so it is exported as a comma; a repeated name is numbered.'
        );
    }

    public function testThePreviewReportsAMissingOrAnUnacceptableCertificate(): void
    {
        $member = $this->createMember('req_imp_cert_bad');
        $archive = $this->extract(array(
            'activities.csv' => "title,begin_date,certificates\n"
                . "Missing,2026-03-01,nowhere.pdf\n"
                . "Script,2026-03-02,evil.pdf\n"
                . "Fine,2026-03-03,good.pdf\n",
            'evil.pdf' => "<?php echo 'not a certificate';\n",
            'good.pdf' => self::PDF
        ));

        $service = new ImportService($this->getDatabase());
        $rows = $service->readFile($archive['spreadsheet']);
        $result = $service->analyse($rows, $service->resolveMapping($rows), array(
            'duplicates' => 'create',
            'userId' => $member['usr_id'],
            'archive' => $archive['files']
        ));

        $this->assertSame(array('error', 'error', 'create'), array_column($result['rows'], 'status'));
        $this->assertStringContainsString('nowhere.pdf', $result['rows'][0]['messages'][0]);
        $this->assertStringStartsWith('evil.pdf: ', $result['rows'][1]['messages'][0]);
        $this->assertSame(array(), $this->storedNames(), 'The preview attaches nothing.');
    }

    public function testCertificatesNamedWithoutAnArchiveLeaveTheRowStandingWithANote(): void
    {
        $member = $this->createMember('req_imp_cert_none');
        $csv = sys_get_temp_dir() . '/req_imp_cert_' . uniqid('', true) . '.csv';
        file_put_contents($csv, "title,begin_date,certificates\nSeminar,2026-03-01,a.pdf\n");
        $this->temporaryFiles[] = $csv;

        $service = new ImportService($this->getDatabase());
        $rows = $service->readFile($csv);
        $result = $service->analyse($rows, $service->resolveMapping($rows), array(
            'duplicates' => 'create',
            'userId' => $member['usr_id']
        ));

        $this->assertSame('create', $result['rows'][0]['status']);
        $this->assertCount(1, $result['rows'][0]['messages']);
    }

    public function testAnEntryThatLeavesTheArchiveIsRefused(): void
    {
        $this->assertRefusedWith('PLG_REQUIREMENTS_IMPORT_ARCHIVE_UNSAFE', function (): void {
            $this->extract(array('activities.csv' => "title,begin_date\n", '../escape.pdf' => self::PDF));
        }, array('../escape.pdf'));
    }

    public function testAnArchiveNeedsExactlyOneSpreadsheet(): void
    {
        $this->assertRefusedWith('PLG_REQUIREMENTS_IMPORT_ARCHIVE_NO_SPREADSHEET', function (): void {
            $this->extract(array('a.pdf' => self::PDF));
        });
        $this->assertRefusedWith('PLG_REQUIREMENTS_IMPORT_ARCHIVE_SEVERAL_SPREADSHEETS', function (): void {
            $this->extract(array('one.csv' => "title\n", 'two.xlsx' => 'x'));
        });
    }

    /**
     * A browser reads a plain `filename` as Latin-1, so the real name travels in `filename*`.
     */
    public function testTheDownloadNameSurvivesNonAsciiCharacters(): void
    {
        $file = new ActivityFile($this->getDatabase());
        $file->setValue('raf_name', '2026-06-25_ÖFdV_Life Insurance "6CPD".pdf');

        $method = new \ReflectionMethod(ActivityFileService::class, 'dispositionName');
        $header = $method->invoke(new ActivityFileService($this->getDatabase()), $file);

        $this->assertStringContainsString(
            "filename*=UTF-8''" . rawurlencode('2026-06-25_ÖFdV_Life Insurance 6CPD.pdf'),
            $header
        );
        $this->assertMatchesRegularExpression('/^filename="[\x20-\x7E]+"; /', $header);
    }

    /**
     * @param array<string,string> $entries path in the archive => content
     * @return array{directory:string,spreadsheet:string,files:array<string,string>}
     */
    private function extract(array $entries): array
    {
        $zipPath = sys_get_temp_dir() . '/req_imp_cert_' . uniqid('', true) . '.zip';
        $this->temporaryFiles[] = $zipPath;
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        $archive = ImportArchive::extract($zipPath);
        $this->directories[] = $archive['directory'];

        return $archive;
    }

    /**
     * @param array{spreadsheet:string,files:array<string,string>} $archive
     * @return array{summary:array<string,int>,rows:array<int,array<string,mixed>>}
     */
    private function import(array $archive, ?int $userId): array
    {
        $service = new ImportService($this->getDatabase());
        $rows = $service->readFile($archive['spreadsheet']);

        return $service->import($rows, $service->resolveMapping($rows), array_filter(array(
            'duplicates' => 'create',
            'userId' => $userId,
            'archive' => $archive['files']
        ), static fn(mixed $value): bool => $value !== null));
    }

    /**
     * @return array<int,string>
     */
    private function storedNames(): array
    {
        return $this->getDatabase()->queryPrepared(
            'SELECT raf_name FROM ' . RequirementsTables::activityFiles() . ' ORDER BY raf_name, raf_id'
        )->fetchAll(\PDO::FETCH_COLUMN);
    }
}
