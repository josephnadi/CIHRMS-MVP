<?php

namespace App\Integrations\Drivers\Microsoft;

use App\Integrations\Contracts\SpreadsheetProvider;

/**
 * Excel-on-OneDrive operations via Graph workbook endpoints.
 * Sheet IDs are OneDrive driveItem IDs; tabs are worksheet names.
 *
 * Note: Workbook endpoints require the file to be an `.xlsx` (open XML) — old
 * `.xls` is unsupported. createSheet() uploads an empty workbook to satisfy this.
 */
class MsGraphExcelDriver extends MsGraphBaseDriver implements SpreadsheetProvider
{
    public function capability(): string
    {
        return 'spreadsheet';
    }

    public function createSheet(string $name, ?string $folderId = null): string
    {
        return $this->track('sheet.create', ['name' => $name, 'folder' => $folderId], function () use ($name, $folderId) {
            $filename = str_ends_with($name, '.xlsx') ? $name : "{$name}.xlsx";
            $path = $folderId ? "{$folderId}/{$filename}" : $filename;

            // Minimal empty .xlsx — Microsoft Graph stores it as a Workbook on upload.
            $emptyWorkbook = $this->emptyWorkbookBytes();

            $response = $this->putBinary(
                "/me/drive/root:/{$path}:/content",
                $emptyWorkbook,
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            );

            return (string) $response->json('id');
        });
    }

    /** @param  array<int, mixed>  $row */
    public function appendRow(string $sheetId, string $tab, array $row): void
    {
        $this->track('sheet.append_row', ['sheet' => $sheetId, 'tab' => $tab, 'cols' => count($row)], function () use ($sheetId, $tab, $row) {
            // 1) figure out where to write — read the used range to know the next free row
            $used = $this->get("/me/drive/items/{$sheetId}/workbook/worksheets('{$tab}')/usedRange")->json();
            $nextRow = ((int) ($used['rowCount'] ?? 0)) + 1;
            $colCount = max(count($row), 1);
            $endCol = $this->columnLetter($colCount);
            $address = "A{$nextRow}:{$endCol}{$nextRow}";

            $this->patch(
                "/me/drive/items/{$sheetId}/workbook/worksheets('{$tab}')/range(address='{$address}')",
                ['values' => [array_values($row)]]
            );
        });
    }

    /** @return array<int, array<int, mixed>> */
    public function readSheet(string $sheetId, string $range): array
    {
        return $this->track('sheet.read', ['sheet' => $sheetId, 'range' => $range], function () use ($sheetId, $range) {
            // range comes as 'Sheet1!A1:C100' — split tab/address
            [$tab, $address] = array_pad(explode('!', $range, 2), 2, null);
            $endpoint = $address
                ? "/me/drive/items/{$sheetId}/workbook/worksheets('{$tab}')/range(address='{$address}')"
                : "/me/drive/items/{$sheetId}/workbook/worksheets('{$tab}')/usedRange";

            $values = (array) data_get($this->get($endpoint)->json(), 'values', []);

            return $values;
        });
    }

    public function clearRange(string $sheetId, string $range): void
    {
        $this->track('sheet.clear', ['sheet' => $sheetId, 'range' => $range], function () use ($sheetId, $range) {
            [$tab, $address] = array_pad(explode('!', $range, 2), 2, null);
            $address = $address ?? $range;
            $this->post(
                "/me/drive/items/{$sheetId}/workbook/worksheets('{$tab}')/range(address='{$address}')/clear",
                ['applyTo' => 'Contents']
            );
        });
    }

    /** Convert 1-based column number to Excel-style letter (1=A, 27=AA). */
    protected function columnLetter(int $col): string
    {
        $letter = '';
        while ($col > 0) {
            $remainder = ($col - 1) % 26;
            $letter = chr(65 + $remainder).$letter;
            $col = intdiv($col - 1, 26);
        }
        return $letter ?: 'A';
    }

    /** Bytes of an empty .xlsx — built once and cached on disk to avoid recomputation. */
    protected function emptyWorkbookBytes(): string
    {
        $cachePath = storage_path('app/integrations/empty.xlsx');
        if (! is_dir(dirname($cachePath))) {
            mkdir(dirname($cachePath), 0755, true);
        }
        if (! file_exists($cachePath)) {
            // Defer to PhpSpreadsheet (already in the project via maatwebsite/excel)
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($cachePath);
        }
        return (string) file_get_contents($cachePath);
    }
}
