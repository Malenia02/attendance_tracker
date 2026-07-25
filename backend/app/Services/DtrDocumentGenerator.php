<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use RuntimeException;
use Throwable;
use ZipArchive;

class DtrDocumentGenerator
{
    private const WORD_NAMESPACE = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    public function generate(array $reports, string $destination): void
    {
        $template = resource_path('templates/DTR-format-1.docx');

        if (! is_file($template)) {
            throw new RuntimeException('The DTR Word template is unavailable.');
        }

        if (! copy($template, $destination)) {
            throw new RuntimeException('The DTR document could not be created.');
        }

        $archive = new ZipArchive();

        if ($archive->open($destination) !== true) {
            @unlink($destination);
            throw new RuntimeException('The generated DTR document could not be opened.');
        }

        $failure = null;

        try {
            $documentXml = $archive->getFromName('word/document.xml');

            if ($documentXml === false) {
                throw new RuntimeException('The DTR template document content is missing.');
            }

            $document = new DOMDocument();
            $document->preserveWhiteSpace = true;
            $document->formatOutput = false;

            if (! $document->loadXML($documentXml)) {
                throw new RuntimeException('The DTR template contains invalid document XML.');
            }

            $xpath = new DOMXPath($document);
            $xpath->registerNamespace('w', self::WORD_NAMESPACE);
            $formBoxes = [];

            foreach ($xpath->query('//w:txbxContent') as $box) {
                if (str_contains($box->textContent, 'DAILY TIME RECORD')) {
                    $formBoxes[] = $box;
                }
            }

            if (count($formBoxes) < 1) {
                throw new RuntimeException('No DTR forms were found in the Word template.');
            }

            foreach (array_slice($reports, 0, count($formBoxes)) as $index => $report) {
                $this->fillForm($document, $xpath, $formBoxes[$index], $report);
            }

            $archive->addFromString('word/document.xml', $document->saveXML());
        } catch (Throwable $exception) {
            $failure = $exception;
        } finally {
            $archive->close();
        }

        if ($failure) {
            @unlink($destination);
            throw $failure;
        }
    }

    private function fillForm(
        DOMDocument $document,
        DOMXPath $xpath,
        DOMNode $box,
        array $report
    ): void {
        foreach ($xpath->query('.//w:t', $box) as $textNode) {
            if (trim($textNode->textContent) === '(Name)') {
                $textNode->nodeValue = mb_strtoupper($report['full_name']);
                break;
            }
        }

        $infoTable = null;
        $dailyTable = null;

        foreach ($xpath->query('.//w:tbl', $box) as $table) {
            $firstRow = $xpath->query('./w:tr[1]', $table)->item(0);
            $firstRowText = $firstRow?->textContent ?? '';

            if (str_contains($firstRowText, 'For the month of')) {
                $infoTable = $table;
            }

            if (str_starts_with(trim($firstRowText), 'Day')) {
                $dailyTable = $table;
            }
        }

        if ($infoTable) {
            $infoRows = $xpath->query('./w:tr', $infoTable);
            $firstRow = $infoRows->item(0);
            $cells = $firstRow ? $xpath->query('./w:tc', $firstRow) : null;

            if ($cells && $cells->length > 1) {
                $this->setCellText($document, $xpath, $cells->item(1), $report['month_label']);
            }

            $regularDayRow = $infoRows->item(1);
            $regularDayCells = $regularDayRow ? $xpath->query('./w:tc', $regularDayRow) : null;

            if ($regularDayCells && $regularDayCells->length > 2) {
                $this->setCellText(
                    $document,
                    $xpath,
                    $regularDayCells->item(2),
                    $report['official_hours'] ?? ''
                );
            }

            $saturdayRow = $infoRows->item(2);
            $saturdayCells = $saturdayRow ? $xpath->query('./w:tc', $saturdayRow) : null;

            if ($saturdayCells && $saturdayCells->length > 2) {
                $this->setCellText(
                    $document,
                    $xpath,
                    $saturdayCells->item(2),
                    $report['saturday_hours'] ?? ''
                );
            }
        }

        if (! $dailyTable) {
            throw new RuntimeException('A daily attendance table is missing from the DTR template.');
        }

        $days = collect($report['daily_records'])->keyBy('day_number');
        $rows = $xpath->query('./w:tr', $dailyTable);
        $totalDeficiencyMinutes = 0;

        for ($day = 1; $day <= 31; $day++) {
            $row = $rows->item($day + 1);

            if (! $row) {
                continue;
            }

            $cells = $xpath->query('./w:tc', $row);
            $record = $days->get($day);

            if (! $record) {
                continue;
            }

            $deficiencyMinutes = (int) $record['late_minutes'] + (int) $record['undertime_minutes'];
            $totalDeficiencyMinutes += $deficiencyMinutes;
            $label = $this->statusLabel($record['status']);

            if ($label && ! $record['morning_time_in'] && ! $record['afternoon_time_in']) {
                $this->setCellText($document, $xpath, $cells->item(1), $label);
            } else {
                $this->setCellText($document, $xpath, $cells->item(1), $this->formatTime($record['morning_time_in']));
                $this->setCellText($document, $xpath, $cells->item(2), $this->formatTime($record['morning_time_out']));
                $this->setCellText($document, $xpath, $cells->item(3), $this->formatTime($record['afternoon_time_in']));
                $this->setCellText($document, $xpath, $cells->item(4), $this->formatTime($record['afternoon_time_out']));
            }

            if ($deficiencyMinutes > 0) {
                $this->setCellText($document, $xpath, $cells->item(5), (string) intdiv($deficiencyMinutes, 60));
                $this->setCellText($document, $xpath, $cells->item(6), str_pad(
                    (string) ($deficiencyMinutes % 60),
                    2,
                    '0',
                    STR_PAD_LEFT
                ));
            }
        }

        $totalRow = $rows->item(33);

        if ($totalRow) {
            $totalCells = $xpath->query('./w:tc', $totalRow);

            if ($totalCells->length >= 4) {
                $this->setCellText(
                    $document,
                    $xpath,
                    $totalCells->item(2),
                    (string) intdiv($totalDeficiencyMinutes, 60)
                );
                $this->setCellText(
                    $document,
                    $xpath,
                    $totalCells->item(3),
                    str_pad((string) ($totalDeficiencyMinutes % 60), 2, '0', STR_PAD_LEFT)
                );
            }
        }
    }

    private function setCellText(
        DOMDocument $document,
        DOMXPath $xpath,
        ?DOMNode $cell,
        ?string $value
    ): void {
        if (! $cell || $value === null || $value === '') {
            return;
        }

        $textNodes = $xpath->query('.//w:t', $cell);

        if ($textNodes->length > 0) {
            $textNodes->item(0)->nodeValue = $value;

            for ($index = 1; $index < $textNodes->length; $index++) {
                $textNodes->item($index)->nodeValue = '';
            }

            return;
        }

        $paragraph = $xpath->query('./w:p[1]', $cell)->item(0);

        if (! $paragraph) {
            $paragraph = $document->createElementNS(self::WORD_NAMESPACE, 'w:p');
            $cell->appendChild($paragraph);
        }

        $run = $document->createElementNS(self::WORD_NAMESPACE, 'w:r');
        $runProperties = $document->createElementNS(self::WORD_NAMESPACE, 'w:rPr');
        $fontSize = $document->createElementNS(self::WORD_NAMESPACE, 'w:sz');
        $fontSize->setAttributeNS(self::WORD_NAMESPACE, 'w:val', '14');
        $runProperties->appendChild($fontSize);
        $text = $document->createElementNS(self::WORD_NAMESPACE, 'w:t');
        $text->appendChild($document->createTextNode($value));
        $run->appendChild($runProperties);
        $run->appendChild($text);
        $paragraph->appendChild($run);
    }

    private function formatTime(?string $value): string
    {
        return $value ? date('g:i', strtotime($value)) : '';
    }

    private function statusLabel(string $status): ?string
    {
        return match ($status) {
            'Missing', 'Absent' => 'ABSENT',
            'Leave' => 'LEAVE',
            'Holiday' => 'HOLIDAY',
            'Official Business' => 'OB',
            'Work From Home' => 'WFH',
            default => null,
        };
    }
}
