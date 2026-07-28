<?php

declare(strict_types=1);

namespace EruoFood\Analytics\Domain\Enum;

/** A report export format. CSV is native; XLSX & PDF are architecture-ready. */
enum ExportFormat: string
{
    case Csv = 'csv';
    case Xlsx = 'xlsx';
    case Pdf = 'pdf';

    public function mimeType(): string
    {
        return match ($this) {
            self::Csv => 'text/csv',
            self::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            self::Pdf => 'application/pdf',
        };
    }

    public function extension(): string
    {
        return $this->value;
    }
}
