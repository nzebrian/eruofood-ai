<?php

declare(strict_types=1);

use EruoFood\Analytics\Domain\Enum\ExportFormat;
use EruoFood\Analytics\Domain\Report\Report;
use EruoFood\Analytics\Domain\ValueObject\DateRange;
use EruoFood\Analytics\Infrastructure\Export\NativeReportExporter;

function sampleReport(): Report
{
    return Report::ready(
        'r1',
        'revenue',
        'Revenue report',
        new DateRange(new DateTimeImmutable('2026-09-01'), new DateTimeImmutable('2026-09-02')),
        ['Date', 'Revenue (minor)'],
        [['2026-09-01', 100000], ['2026-09-02', 250000]],
        new DateTimeImmutable(),
    );
}

it('exports a report to CSV with a header and rows', function (): void {
    $result = (new NativeReportExporter())->export(sampleReport(), ExportFormat::Csv);
    expect($result->mimeType)->toBe('text/csv')
        ->and($result->filename)->toContain('.csv')
        ->and($result->content)->toContain('Date,"Revenue (minor)"')
        ->and($result->content)->toContain('2026-09-02,250000');
});

it('produces a well-formed PDF header', function (): void {
    $result = (new NativeReportExporter())->export(sampleReport(), ExportFormat::Pdf);
    expect($result->mimeType)->toBe('application/pdf')
        ->and($result->content)->toStartWith('%PDF-1.4')
        ->and($result->content)->toContain('%%EOF');
});
