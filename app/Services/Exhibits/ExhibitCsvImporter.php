<?php

namespace App\Services\Exhibits;

use App\Models\Exhibit;
use App\Models\ExhibitProject;
use App\Models\ExhibitProjectType;
use App\Models\ExhibitStatus;
use Illuminate\Support\Str;

/**
 * Header-driven importer for the historical Grad Show Numbers exports.
 * Maps columns by name (not position) so it survives the 4 layout eras
 * (2015; 2016–19 `Status,…,Application`; 2022 `Project Type`+`Assigned
 * Device`; 2024–26 full). Resolves the status / project-type cells to the
 * editable catalogs (matching by name/slug, creating when genuinely new).
 *
 * Historical rows are name-only — no Snipe user/asset linking, and PII
 * columns (email/phone/student id) are intentionally dropped. The file is
 * read from a temp path and never persisted.
 */
class ExhibitCsvImporter
{
    /** @var array<string,int> resolved-status name(lower) => id cache */
    private array $statusCache = [];

    /** @var array<string,int> resolved-type name(lower) => id cache */
    private array $typeCache = [];

    public function import(string $path, Exhibit $exhibit, int $year): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException('Could not open the uploaded file.');
        }

        $header = fgetcsv($handle);
        if ($header === false || $header === null) {
            fclose($handle);
            throw new \RuntimeException('The file appears to be empty.');
        }

        $map = $this->mapColumns($header);
        if (! isset($map['name'])) {
            fclose($handle);
            throw new \RuntimeException('No "Name" column found — is this a Grad Show export?');
        }

        $imported = 0;
        $skipped = 0;
        $adminId = auth()->id();

        while (($cells = fgetcsv($handle)) !== false) {
            $name = $this->cleanName($this->cell($cells, $map, 'name'));
            if ($name === '') {           // group-header / total / blank rows
                $skipped++;
                continue;
            }

            $statusId = $this->resolveStatus($this->cell($cells, $map, 'status'));

            // Project type only when there's a real "Project Type" column;
            // older years' free-text "Application" goes to details instead.
            $typeId = null;
            $details = $this->cell($cells, $map, 'details');
            if (isset($map['project_type'])) {
                $typeId = $this->resolveType($this->cell($cells, $map, 'project_type'));
            } elseif (isset($map['application'])) {
                $app = $this->cell($cells, $map, 'application');
                $details = trim($app.($details ? ' — '.$details : ''));
            }

            ExhibitProject::updateOrCreate(
                ['exhibit_id' => $exhibit->id, 'year' => $year, 'student_name' => $name],
                [
                    'status_id' => $statusId,
                    'project_type_id' => $typeId,
                    'project_details' => $details ?: null,
                    'requested_device' => $this->cell($cells, $map, 'device') ?: null,
                    'peripherals' => $this->cell($cells, $map, 'peripherals') ?: null,
                    'submitted_file' => $this->bool($this->cell($cells, $map, 'submitted')),
                    'approved' => $this->bool($this->cell($cells, $map, 'approved')),
                    'tdx_id' => $this->cell($cells, $map, 'tdx') ?: null,
                    'notes' => $this->cell($cells, $map, 'notes') ?: null,
                    'created_by' => $adminId,
                ]
            );
            $imported++;
        }

        fclose($handle);

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    /** Match known fields to column indexes by fuzzy header name. */
    private function mapColumns(array $header): array
    {
        $map = [];
        foreach ($header as $i => $raw) {
            $h = strtolower(trim((string) preg_replace('/\xEF\xBB\xBF/', '', $raw)));
            $h = preg_replace('/\s+/', ' ', $h);
            if ($h === '') {
                continue;
            }
            // first exact/contains match wins; don't overwrite an existing hit
            $assign = function (string $key) use (&$map, $i) {
                if (! isset($map[$key])) {
                    $map[$key] = $i;
                }
            };

            if ($h === 'status') $assign('status');
            elseif ($h === 'name') $assign('name');
            elseif (str_contains($h, 'submitted')) $assign('submitted');
            elseif (str_contains($h, 'approved')) $assign('approved');
            elseif (str_contains($h, 'project type')) $assign('project_type');
            elseif ($h === 'application') $assign('application');
            elseif (str_contains($h, 'project details')) $assign('details');
            elseif (str_contains($h, 'requested device') || str_contains($h, 'assigned device') || str_contains($h, 'equipment id')) $assign('device');
            elseif (str_contains($h, 'peripheral')) $assign('peripherals');
            elseif (str_contains($h, 'tdx')) $assign('tdx');
            elseif ($h === 'notes') $assign('notes');
        }

        return $map;
    }

    private function cell(array $cells, array $map, string $key): string
    {
        if (! isset($map[$key])) {
            return '';
        }

        return trim((string) ($cells[$map[$key]] ?? ''));
    }

    private function cleanName(string $name): string
    {
        // strip the trailing "*" marker used in older sheets
        return trim(rtrim(trim($name), '* '));
    }

    private function bool(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['true', 'yes', '1', 'x', 'y'], true);
    }

    private function resolveStatus(string $raw): int
    {
        $name = trim($raw);
        if ($name === '') {
            $name = 'Undetermined';
        }
        $key = strtolower($name);
        if (isset($this->statusCache[$key])) {
            return $this->statusCache[$key];
        }

        $status = ExhibitStatus::whereRaw('LOWER(name) = ?', [$key])->first()
            ?? ExhibitStatus::where('slug', Str::slug($name, '_'))->first()
            ?? ExhibitStatus::create([
                'name' => $name,
                'color' => '#bdc3c7',
                'sort_order' => 99,
                'active' => true,
            ]);

        return $this->statusCache[$key] = $status->id;
    }

    private function resolveType(string $raw): ?int
    {
        $name = trim($raw);
        if ($name === '') {
            return null;
        }
        $key = strtolower($name);
        if (isset($this->typeCache[$key])) {
            return $this->typeCache[$key];
        }

        $type = ExhibitProjectType::whereRaw('LOWER(name) = ?', [$key])->first()
            ?? ExhibitProjectType::where('slug', Str::slug($name, '_'))->first()
            ?? ExhibitProjectType::create([
                'name' => $name,
                'color' => '#95a5a6',
                'sort_order' => 99,
                'active' => true,
            ]);

        return $this->typeCache[$key] = $type->id;
    }
}
