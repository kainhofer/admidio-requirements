<?php
namespace AdmidioPlugin\Requirements\Service;

use Admidio\Infrastructure\Utils\PdfUtils;

/**
 * The activity list as one PDF: a table with everything the activities hold, followed by their
 * certificates - a PDF certificate with its own pages, an image on a page of its own - in the order
 * of the table, so that the file can be handed to a reviewer as it is.
 *
 * The builder takes rows that are already prepared and read from the database nothing, which keeps
 * it usable (and testable) without one. ActivityExportService::pdf() prepares them.
 */
final class ActivityPdfBuilder
{
    /**
     * @param string $heading title of the document
     * @param array<int,array{key?:string,label:string}> $columns the columns of the table; a row has
     *        one entry per column, as plain text with line breaks, which is escaped here
     * @param array<int,array{cells:array<int,string>,certificates:array<int,array{name:string,path:string,mime:string}>}> $rows
     * @param string $certificatesHeading what stands above a certificate page, followed by the activity
     * @return string the PDF
     */
    public static function build(
        string $heading,
        array $columns,
        array $rows,
        string $certificatesHeading,
        ?string $language = null
    ): string {
        $pdf = PdfUtils::createDocument('L', $heading, $language);

        $html = '<table border="1" cellpadding="3"><thead><tr>';
        foreach ($columns as $column) {
            $html .= '<th style="font-weight:bold;background-color:#C7C7C7;">' . self::text($column['label']) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row['cells'] as $cell) {
                $html .= '<td>' . self::text($cell) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
        $pdf->writeTable($html);

        foreach ($rows as $row) {
            foreach ($row['certificates'] as $certificate) {
                self::appendCertificate($pdf, $certificate, $certificatesHeading . ': ' . $row['cells'][self::titleIndex($columns)]);
            }
        }

        return $pdf->getOutPDFString();
    }

    /**
     * @param array{name:string,path:string,mime:string} $certificate
     */
    private static function appendCertificate($pdf, array $certificate, string $caption): void
    {
        $data = @file_get_contents($certificate['path']);
        if ($data === false || $data === '') {
            return;
        }

        if ($certificate['mime'] === 'application/pdf') {
            try {
                $source = $pdf->setImportSourceData($data);
                $pdf->appendDocument($source);
            } catch (\Throwable) {
                // A certificate this library cannot read (encrypted, damaged) is left out; the
                // table still names it, and the ZIP export has the original.
            }
            return;
        }

        if (!in_array($certificate['mime'], array('image/jpeg', 'image/png'), true)) {
            return;
        }
        // Fit the image into the text area of the page (about 680 x 900 px), keeping its proportions.
        $size = @getimagesizefromstring($data) ?: array(680, 680);
        $scale = min(680 / max(1, $size[0]), 900 / max(1, $size[1]));
        $width = max(1, (int) round($size[0] * $scale));
        $pdf->addPage(array(
            'format' => 'A4',
            'orientation' => 'P',
            'autobreak' => true,
            'margin' => array('PL' => 10, 'PR' => 10, 'PT' => 0, 'HB' => 0, 'CT' => 20, 'CB' => 25, 'FT' => 0, 'PB' => 0)
        ));
        $pdf->writeTable(
            '<p><b>' . self::text($caption) . '</b><br/>' . self::text($certificate['name']) . '</p>'
            . '<img src="data:' . $certificate['mime'] . ';base64,' . base64_encode($data) . '" width="' . $width . '"/>'
        );
    }

    /** The column that holds the title, which a certificate page names; the first one if none does. */
    private static function titleIndex(array $columns): int
    {
        foreach ($columns as $index => $column) {
            if (($column['key'] ?? '') === 'title') {
                return $index;
            }
        }
        return 0;
    }

    private static function text(string $value): string
    {
        return nl2br(htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
    }
}
