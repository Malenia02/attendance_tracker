<?php

namespace Tests\Unit;

use App\Services\DtrDocumentGenerator;
use App\Support\DtrPeriod;
use Carbon\Carbon;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;

class DtrDocumentGeneratorTest extends TestCase
{
    public static function reportingPeriods(): array
    {
        $cases = [];
        foreach (['GIP', 'Job Order'] as $type) {
            foreach (DtrPeriod::values() as $period) {
                foreach (['2026-07-01', '2026-02-01', '2028-02-01', '2026-04-01'] as $month) {
                    $cases["{$type} {$period} {$month}"] = [$type, $period, $month];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('reportingPeriods')]
    public function test_exports_keep_all_numbered_rows_but_only_fill_and_total_the_selected_dates(
        string $type,
        string $period,
        string $month
    ): void {
        $month = Carbon::parse($month);
        [$start, $end] = DtrPeriod::bounds($month, $period);
        $directory = storage_path('framework/testing/dtr');
        File::ensureDirectoryExists($directory);
        $path = $directory.'/period-test.docx';
        // Deliberately include the entire month, including invalid dates in short months:
        // the export must enforce the period even when its input contains extra records.
        $report = [
            'personnel_type' => $type,
            'full_name' => 'Juan Dela Cruz',
            'month_label' => DtrPeriod::label($month, $period),
            'period' => $period,
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'official_hours' => '7:00-12:00 / 1:00-5:00',
            'saturday_hours' => 'N/A',
            'certification' => ['version_number' => 2],
            'daily_records' => array_map(fn (int $day) => [
                'day_number' => $day,
                'status' => $day === 2 ? 'Holiday' : ($day === 17 ? 'Rest Day' : 'Present'),
                'morning_time_in' => in_array($day, [2, 17]) ? null : '07:10:00',
                'morning_time_out' => in_array($day, [2, 17]) ? null : '12:00:00',
                'afternoon_time_in' => in_array($day, [2, 17]) ? null : '13:00:00',
                'afternoon_time_out' => in_array($day, [2, 17]) ? null : '17:00:00',
                'late_minutes' => in_array($day, [2, 17]) ? 0 : 10,
                'undertime_minutes' => 0,
            ], range(1, 31)),
        ];

        try {
            app(DtrDocumentGenerator::class)->generate([$report], $path);
            $archive = new ZipArchive;
            $this->assertTrue($archive->open($path) === true);
            $xml = $archive->getFromName('word/document.xml');
            $archive->close();
            $document = new DOMDocument;
            $this->assertTrue($document->loadXML($xml));
            $xpath = new DOMXPath($document);
            $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            $tables = $xpath->query('//w:tbl[w:tr[3]/w:tc[1]//w:t[text()="1"]]');
            $copies = $type === 'Job Order' ? 2 : 6;
            $this->assertCount($copies, $tables);
            $this->assertSame($copies, substr_count($xml, 'JUAN DELA CRUZ'));
            $this->assertSame($copies, substr_count($xml, 'DAILY TIME RECORD - AMENDED V2'));
            if ($type === 'Job Order') {
                $this->assertStringContainsString('Civil Service Form No. 1', $xml);
                $this->assertStringContainsString('OLIVER B. OMBOS', $xml);
                $this->assertStringContainsString($report['month_label'], $xml);
                $this->assertStringNotContainsString('For the Month of _____', $xml);
                $this->assertStringContainsString('Regular days: 7:00-12:00 / 1:00-5:00', $xml);
            }

            foreach ($tables as $table) {
                $rows = $xpath->query('./w:tr', $table);
                $this->assertCount(34, $rows);
                $expectedTotal = 0;
                for ($day = 1; $day <= 31; $day++) {
                    $cells = $xpath->query('./w:tc', $rows->item($day + 1));
                    $this->assertSame((string) $day, $cells->item(0)->textContent);
                    if ($day < $start->day || $day > $end->day) {
                        for ($column = 1; $column <= 6; $column++) {
                            $this->assertSame('', trim($cells->item($column)->textContent), "Day {$day}, column {$column}");
                        }
                    } elseif (in_array($day, [2, 17])) {
                        $this->assertSame($day === 2 ? 'HOLIDAY' : 'REST DAY', $cells->item(1)->textContent);
                    } else {
                        $this->assertSame('7:10', $cells->item(1)->textContent);
                        $this->assertSame('5:00', $cells->item(4)->textContent);
                        $expectedTotal += 10;
                    }
                }
                $totals = $xpath->query('./w:tc', $rows->item(33));
                $this->assertSame((string) intdiv($expectedTotal, 60), $totals->item($type === 'Job Order' ? 5 : 2)->textContent);
                $this->assertSame(str_pad((string) ($expectedTotal % 60), 2, '0', STR_PAD_LEFT), $totals->item($type === 'Job Order' ? 6 : 3)->textContent);
            }
        } finally {
            File::delete($path);
        }
    }

    public function test_it_populates_the_official_word_template(): void
    {
        $directory = storage_path('framework/testing/dtr');
        File::ensureDirectoryExists($directory);
        $path = $directory.'/generated-test.docx';
        $report = [
            'full_name' => 'Juan Dela Cruz',
            'month_label' => 'July 2026',
            'official_hours' => '7:00-12:00 / 1:00-5:00',
            'saturday_hours' => 'N/A',
            'certification' => ['version_number' => 2],
            'daily_records' => [
                [
                    'day_number' => 1,
                    'status' => 'Present',
                    'morning_time_in' => '2026-07-01T07:15:00+08:00',
                    'morning_time_out' => '2026-07-01T12:00:00+08:00',
                    'afternoon_time_in' => '2026-07-01T13:00:00+08:00',
                    'afternoon_time_out' => '2026-07-01T17:00:00+08:00',
                    'late_minutes' => 15,
                    'undertime_minutes' => 0,
                ],
                [
                    'day_number' => 2,
                    'status' => 'Rest Day',
                    'morning_time_in' => null,
                    'morning_time_out' => null,
                    'afternoon_time_in' => null,
                    'afternoon_time_out' => null,
                    'late_minutes' => 0,
                    'undertime_minutes' => 0,
                ],
                [
                    'day_number' => 3,
                    'status' => 'Holiday',
                    'morning_time_in' => null,
                    'morning_time_out' => null,
                    'afternoon_time_in' => null,
                    'afternoon_time_out' => null,
                    'late_minutes' => 0,
                    'undertime_minutes' => 0,
                ],
            ],
        ];

        try {
            app(DtrDocumentGenerator::class)->generate([$report], $path);

            $archive = new ZipArchive;
            $this->assertTrue($archive->open($path) === true);
            $documentXml = $archive->getFromName('word/document.xml');
            $archive->close();

            $this->assertIsString($documentXml);
            $this->assertStringContainsString('JUAN DELA CRUZ', $documentXml);
            $this->assertSame(6, substr_count($documentXml, 'JUAN DELA CRUZ'));
            $this->assertStringNotContainsString('(Name)', $documentXml);
            $this->assertStringContainsString('July 2026', $documentXml);
            $this->assertStringContainsString('7:15', $documentXml);
            $this->assertStringContainsString('7:00-12:00', $documentXml);
            $this->assertStringContainsString('REST DAY', $documentXml);
            $this->assertStringContainsString('HOLIDAY', $documentXml);
            $this->assertSame(
                6,
                substr_count($documentXml, 'DAILY TIME RECORD - AMENDED V2')
            );
            $this->assertSame(186, substr_count($documentXml, 'w:hRule="exact"'));
            $this->assertStringContainsString('<w:noWrap', $documentXml);
        } finally {
            File::delete($path);
        }
    }
}
