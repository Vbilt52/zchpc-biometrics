<?php

namespace App\Services;

use Illuminate\Http\Response;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;

class TabularReportExporter
{
    /** All cell values are text: preserve IDs and prevent formula injection. */
    public function download(string $format, string $filename, string $title, string $description, array $headers, iterable $rows): Response
    {
        $path = tempnam(sys_get_temp_dir(), 'zchpc-report-');
        try {
            if ($format === 'xlsx') {
                $book = new Spreadsheet;
                $sheet = $book->getActiveSheet()->setTitle('Access events');
                foreach ([[$title], [$description], $headers, ...$rows] as $rowIndex => $row) {
                    foreach ($row as $column => $value) {
                        $sheet->setCellValueExplicit([$column + 1, $rowIndex + 1], (string) $value, DataType::TYPE_STRING);
                    }
                }
                $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
                $sheet->freezePane('A4');
                $sheet->setAutoFilter('A3:'.$lastColumn.$sheet->getHighestRow());
                $sheet->getStyle('A3:'.$lastColumn.'3')->getFont()->setBold(true);
                foreach (range('A', $lastColumn) as $column) {
                    $sheet->getColumnDimension($column)->setWidth(24);
                }
                $sheet->getStyle($sheet->calculateWorksheetDimension())->getAlignment()->setWrapText(true);
                (new Xlsx($book))->save($path);
                $book->disconnectWorksheets();
                $mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
            } else {
                Settings::setOutputEscapingEnabled(true);
                $document = new PhpWord;
                $document->setDefaultFontName('Arial');
                $document->setDefaultFontSize(9);
                $section = $document->addSection(['orientation' => 'landscape', 'marginLeft' => 600, 'marginRight' => 600]);
                $section->addText($title, ['bold' => true, 'size' => 16]);
                $section->addText($description);
                $table = $section->addTable(['borderSize' => 4, 'borderColor' => 'D9D9D9', 'cellMargin' => 90]);
                $width = (int) (14500 / count($headers));
                $table->addRow(null, ['tblHeader' => true]);
                foreach ($headers as $header) {
                    $table->addCell($width, ['bgColor' => 'E8EDF3'])->addText($header, ['bold' => true]);
                }
                foreach ($rows as $row) {
                    $table->addRow(null, ['cantSplit' => true]);
                    foreach ($row as $value) {
                        $table->addCell($width)->addText((string) $value);
                    }
                }
                IOFactory::createWriter($document, 'Word2007')->save($path);
                $mime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
            }

            return response(file_get_contents($path), 200, [
                'Content-Type' => $mime,
                'Content-Disposition' => 'attachment; filename="'.$filename.'.'.$format.'"',
                'Cache-Control' => 'private, no-store',
            ]);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
