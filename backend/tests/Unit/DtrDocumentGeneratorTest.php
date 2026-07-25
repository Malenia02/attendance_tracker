<?php

namespace Tests\Unit;

use App\Services\DtrDocumentGenerator;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

class DtrDocumentGeneratorTest extends TestCase
{
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
            ],
        ];

        try {
            app(DtrDocumentGenerator::class)->generate([$report], $path);

            $archive = new ZipArchive();
            $this->assertTrue($archive->open($path) === true);
            $documentXml = $archive->getFromName('word/document.xml');
            $archive->close();

            $this->assertIsString($documentXml);
            $this->assertStringContainsString('JUAN DELA CRUZ', $documentXml);
            $this->assertStringContainsString('July 2026', $documentXml);
            $this->assertStringContainsString('7:15', $documentXml);
            $this->assertStringContainsString('7:00-12:00', $documentXml);
        } finally {
            File::delete($path);
        }
    }
}
