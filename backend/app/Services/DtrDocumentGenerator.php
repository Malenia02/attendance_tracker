<?php

namespace App\Services;

use App\Support\DtrPeriod;
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
        $isJobOrder = strtoupper(trim($reports[0]['personnel_type'] ?? '')) === 'JOB ORDER';
        $template = resource_path($isJobOrder ? 'templates/DTR-JO.docx' : 'templates/DTR-format-1.docx');

        if (! is_file($template)) {
            throw new RuntimeException('The DTR Word template is unavailable.');
        }

        if (! copy($template, $destination)) {
            throw new RuntimeException('The DTR document could not be created.');
        }

        $archive = new ZipArchive;

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

            $document = new DOMDocument;
            $document->preserveWhiteSpace = true;
            $document->formatOutput = false;

            if (! $document->loadXML($documentXml)) {
                throw new RuntimeException('The DTR template contains invalid document XML.');
            }

            $xpath = new DOMXPath($document);
            $xpath->registerNamespace('w', self::WORD_NAMESPACE);
            if ($isJobOrder) {
                $this->fillJobOrderForms($document, $xpath, $reports);
            } else {
                $formBoxes = [];

                foreach ($xpath->query('//w:txbxContent') as $box) {
                    if (str_contains($box->textContent, 'DAILY TIME RECORD')) {
                        $formBoxes[] = $box;
                    }
                }

                if (count($formBoxes) < 1) {
                    throw new RuntimeException('No DTR forms were found in the Word template.');
                }

                if (count($reports) === 1) {
                    foreach ($formBoxes as $formBox) {
                        $this->fillForm($document, $xpath, $formBox, $reports[0]);
                    }
                } else {
                    foreach (array_slice($reports, 0, count($formBoxes)) as $index => $report) {
                        $this->fillForm($document, $xpath, $formBoxes[$index], $report);
                    }
                }
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
        $version = (int) ($report['certification']['version_number'] ?? 1);

        foreach ($xpath->query('.//w:t', $box) as $textNode) {
            if (! $textNode instanceof DOMElement) {
                continue;
            }

            if ($version > 1 && trim($textNode->textContent) === 'DAILY TIME RECORD') {
                $textNode->nodeValue = "DAILY TIME RECORD - AMENDED V{$version}";
            }

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

        $this->fillDailyTable($document, $xpath, $dailyTable, $report);
    }

    private function fillJobOrderForms(DOMDocument $document, DOMXPath $xpath, array $reports): void
    {
        $formIndex = -1;
        $expectName = false;
        $report = null;

        foreach ($xpath->query('/w:document/w:body/*') as $node) {
            $text = trim($node->textContent);
            if ($node->localName === 'p' && $text === 'DAILY TIME RECORD') {
                $formIndex++;
                $report = count($reports) === 1 ? $reports[0] : ($reports[$formIndex] ?? null);
                $expectName = true;
                $version = (int) ($report['certification']['version_number'] ?? 1);
                if ($version > 1) {
                    $this->setCellText($document, $xpath, $node, "DAILY TIME RECORD - AMENDED V{$version}", 18);
                }

                continue;
            }

            if (! $report) {
                continue;
            }

            if ($node->localName === 'tbl') {
                $this->fillDailyTable($document, $xpath, $node, $report, true);
            } elseif ($node->localName === 'p') {
                if ($expectName) {
                    $this->setCellText($document, $xpath, $node, mb_strtoupper($report['full_name']), 20);
                    $expectName = false;
                } elseif (str_starts_with($text, 'For the Month of')) {
                    $this->setCellText($document, $xpath, $node, 'For the Month of '.$report['month_label'], 20);
                } elseif (str_starts_with($text, 'Official hours of arrival')) {
                    $this->setCellText($document, $xpath, $node, 'Regular days: '.($report['official_hours'] ?? ''), 18);
                } elseif (str_starts_with($text, 'and departure (Saturdays)')) {
                    $this->setCellText($document, $xpath, $node, 'Saturdays: '.($report['saturday_hours'] ?? ''), 18);
                }
            }
        }

        if ($formIndex < 0) {
            throw new RuntimeException('No DTR forms were found in the JO Word template.');
        }
    }

    private function fillDailyTable(
        DOMDocument $document,
        DOMXPath $xpath,
        DOMNode $dailyTable,
        array $report,
        bool $isJobOrder = false
    ): void {
        $period = DtrPeriod::normalize($report['period'] ?? null);
        $firstDay = $period === DtrPeriod::SECOND_HALF ? 16 : 1;
        $lastDay = $period === DtrPeriod::FIRST_HALF ? 15 : 31;
        if (! empty($report['period_start'])) {
            $firstDay = max($firstDay, (int) substr($report['period_start'], 8, 2));
        }
        if (! empty($report['period_end'])) {
            $lastDay = min($lastDay, (int) substr($report['period_end'], 8, 2));
        }
        $days = collect($report['daily_records'])
            ->filter(fn (array $record) => $record['day_number'] >= $firstDay && $record['day_number'] <= $lastDay)
            ->keyBy('day_number');
        $rows = $xpath->query('./w:tr', $dailyTable);
        $totalDeficiencyMinutes = 0;

        for ($day = 1; $day <= 31; $day++) {
            $row = $rows->item($day + 1);

            if (! $row) {
                continue;
            }

            if (! $isJobOrder) {
                $this->setExactRowHeight($document, $xpath, $row, 180);
            }
            $cells = $xpath->query('./w:tc', $row);
            // Keep every numbered row, but clear all attendance cells before filling the selected cutoff.
            for ($column = 1; $column < $cells->length; $column++) {
                $this->setCellText($document, $xpath, $cells->item($column), '');
            }
            $record = $days->get($day);

            if (! $record) {
                continue;
            }

            $deficiencyMinutes = (int) $record['late_minutes'] + (int) $record['undertime_minutes'];
            $totalDeficiencyMinutes += $deficiencyMinutes;
            $label = $this->statusLabel($record['status']);

            if ($label && ! $record['morning_time_in'] && ! $record['afternoon_time_in']) {
                $this->setCellText($document, $xpath, $cells->item(1), $label, $isJobOrder ? 16 : 12, true);
            } else {
                foreach (['morning_time_in', 'morning_time_out', 'afternoon_time_in', 'afternoon_time_out'] as $column => $field) {
                    $this->setCellText($document, $xpath, $cells->item($column + 1), $this->formatTime($record[$field]), $isJobOrder ? 16 : null);
                }
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

            $hoursColumn = $isJobOrder ? 5 : 2;
            $minutesColumn = $isJobOrder ? 6 : 3;
            if ($totalCells->length > $minutesColumn) {
                $this->setCellText(
                    $document,
                    $xpath,
                    $totalCells->item($hoursColumn),
                    (string) intdiv($totalDeficiencyMinutes, 60)
                );
                $this->setCellText(
                    $document,
                    $xpath,
                    $totalCells->item($minutesColumn),
                    str_pad((string) ($totalDeficiencyMinutes % 60), 2, '0', STR_PAD_LEFT)
                );
            }
        }
    }

    private function setCellText(
        DOMDocument $document,
        DOMXPath $xpath,
        ?DOMNode $cell,
        ?string $value,
        ?int $fontSize = null,
        bool $noWrap = false
    ): void {
        if (! $cell || $value === null) {
            return;
        }

        $textNodes = $xpath->query('.//w:t', $cell);

        if ($textNodes->length > 0) {
            $firstTextNode = $textNodes->item(0);
            if ($firstTextNode instanceof DOMElement) {
                $firstTextNode->nodeValue = $value;
            }

            for ($index = 1; $index < $textNodes->length; $index++) {
                $extraTextNode = $textNodes->item($index);
                if ($extraTextNode instanceof DOMElement) {
                    $extraTextNode->nodeValue = '';
                }
            }

            $this->formatCellText($document, $xpath, $cell, $fontSize, $noWrap);

            return;
        }

        if ($value === '') {
            return;
        }

        $paragraph = $cell->localName === 'p' ? $cell : $xpath->query('./w:p[1]', $cell)->item(0);

        if (! $paragraph) {
            $paragraph = $document->createElementNS(self::WORD_NAMESPACE, 'w:p');
            $cell->appendChild($paragraph);
        }

        $run = $document->createElementNS(self::WORD_NAMESPACE, 'w:r');
        $runProperties = $document->createElementNS(self::WORD_NAMESPACE, 'w:rPr');
        $fontSizeElement = $document->createElementNS(self::WORD_NAMESPACE, 'w:sz');
        $fontSizeElement->setAttributeNS(self::WORD_NAMESPACE, 'w:val', (string) ($fontSize ?? 14));
        $runProperties->appendChild($fontSizeElement);
        $text = $document->createElementNS(self::WORD_NAMESPACE, 'w:t');
        $text->appendChild($document->createTextNode($value));
        $run->appendChild($runProperties);
        $run->appendChild($text);
        $paragraph->appendChild($run);
        $this->formatCellText($document, $xpath, $cell, $fontSize, $noWrap);
    }

    private function formatCellText(
        DOMDocument $document,
        DOMXPath $xpath,
        DOMNode $cell,
        ?int $fontSize,
        bool $noWrap
    ): void {
        if ($fontSize) {
            foreach ($xpath->query('.//w:r', $cell) as $run) {
                $runProperties = $xpath->query('./w:rPr[1]', $run)->item(0);

                if (! $runProperties) {
                    $runProperties = $document->createElementNS(self::WORD_NAMESPACE, 'w:rPr');
                    $run->insertBefore($runProperties, $run->firstChild);
                }

                foreach (['w:sz', 'w:szCs'] as $elementName) {
                    $size = $xpath->query('./'.$elementName.'[1]', $runProperties)->item(0);

                    if (! $size) {
                        $size = $document->createElementNS(self::WORD_NAMESPACE, $elementName);
                        $runProperties->appendChild($size);
                    }

                    $size->setAttributeNS(self::WORD_NAMESPACE, 'w:val', (string) $fontSize);
                }
            }
        }

        if ($noWrap) {
            $cellProperties = $xpath->query('./w:tcPr[1]', $cell)->item(0);

            if (! $cellProperties) {
                $cellProperties = $document->createElementNS(self::WORD_NAMESPACE, 'w:tcPr');
                $cell->insertBefore($cellProperties, $cell->firstChild);
            }

            if (! $xpath->query('./w:noWrap', $cellProperties)->length) {
                $cellProperties->appendChild(
                    $document->createElementNS(self::WORD_NAMESPACE, 'w:noWrap')
                );
            }
        }
    }

    private function setExactRowHeight(
        DOMDocument $document,
        DOMXPath $xpath,
        DOMNode $row,
        int $height
    ): void {
        $rowProperties = $xpath->query('./w:trPr[1]', $row)->item(0);

        if (! $rowProperties) {
            $rowProperties = $document->createElementNS(self::WORD_NAMESPACE, 'w:trPr');
            $row->insertBefore($rowProperties, $row->firstChild);
        }

        $rowHeight = $xpath->query('./w:trHeight[1]', $rowProperties)->item(0);

        if (! $rowHeight) {
            $rowHeight = $document->createElementNS(self::WORD_NAMESPACE, 'w:trHeight');
            $rowProperties->appendChild($rowHeight);
        }

        $rowHeight->setAttributeNS(self::WORD_NAMESPACE, 'w:val', (string) $height);
        $rowHeight->setAttributeNS(self::WORD_NAMESPACE, 'w:hRule', 'exact');
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
            'Rest Day' => 'REST DAY',
            'Official Business' => 'OB',
            'Work From Home' => 'WFH',
            default => null,
        };
    }
}
