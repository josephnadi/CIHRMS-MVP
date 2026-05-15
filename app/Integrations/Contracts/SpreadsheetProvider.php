<?php

namespace App\Integrations\Contracts;

interface SpreadsheetProvider extends IntegrationProvider
{
    public function createSheet(string $name, ?string $folderId = null): string;

    /** @param  array<int, mixed>  $row */
    public function appendRow(string $sheetId, string $tab, array $row): void;

    /** @return array<int, array<int, mixed>> */
    public function readSheet(string $sheetId, string $range): array;

    public function clearRange(string $sheetId, string $range): void;
}
