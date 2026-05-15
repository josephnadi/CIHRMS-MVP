<?php

namespace App\Integrations\Drivers\Google;

use App\Integrations\Contracts\SpreadsheetProvider;
use Illuminate\Support\Facades\Http;

/**
 * Google Sheets v4 driver — append-row, read-range, clear-range.
 * For createSheet() we use a single API call to the Sheets `spreadsheets.create`.
 */
class GoogleSheetsDriver extends GoogleBaseDriver implements SpreadsheetProvider
{
    private const API = 'https://sheets.googleapis.com/v4/';

    public function capability(): string
    {
        return 'spreadsheet';
    }

    public function createSheet(string $name, ?string $folderId = null): string
    {
        return $this->track('sheet.create', ['name' => $name, 'folder' => $folderId], function () use ($name, $folderId) {
            $response = $this->post(self::API, 'spreadsheets', [
                'properties' => ['title' => $name],
            ]);
            $sheetId = (string) $response->json('spreadsheetId');

            // If a parent folder is provided, move the newly-created sheet into it via Drive API
            // (Sheets.create always lands in My Drive root).
            if ($folderId) {
                Http::withToken($this->accessToken())
                    ->patch("https://www.googleapis.com/drive/v3/files/{$sheetId}?addParents={$folderId}&removeParents=root&fields=id,parents")
                    ->throw();
            }

            return $sheetId;
        });
    }

    /** @param  array<int, mixed>  $row */
    public function appendRow(string $sheetId, string $tab, array $row): void
    {
        $this->track('sheet.append_row', ['sheet' => $sheetId, 'tab' => $tab, 'cols' => count($row)], function () use ($sheetId, $tab, $row) {
            $range = rawurlencode($tab);
            $this->http(self::API)->post(
                "spreadsheets/{$sheetId}/values/{$range}!A1:append?valueInputOption=USER_ENTERED&insertDataOption=INSERT_ROWS",
                ['values' => [array_values($row)]]
            )->throw();
        });
    }

    /** @return array<int, array<int, mixed>> */
    public function readSheet(string $sheetId, string $range): array
    {
        return $this->track('sheet.read', ['sheet' => $sheetId, 'range' => $range], function () use ($sheetId, $range) {
            $encoded = rawurlencode($range);
            $data = (array) $this->http(self::API)
                ->get("spreadsheets/{$sheetId}/values/{$encoded}")
                ->throw()
                ->json();
            return (array) ($data['values'] ?? []);
        });
    }

    public function clearRange(string $sheetId, string $range): void
    {
        $this->track('sheet.clear', ['sheet' => $sheetId, 'range' => $range], function () use ($sheetId, $range) {
            $encoded = rawurlencode($range);
            $this->post(self::API, "spreadsheets/{$sheetId}/values/{$encoded}:clear");
        });
    }
}
