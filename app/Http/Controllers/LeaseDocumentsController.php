<?php

namespace App\Http\Controllers;

use App\Helpers\Helper;
use App\Models\Order;
use App\Services\Leasing\LeaseDocumentParser;
use App\Services\Leasing\ScheduleIntake;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The drag-and-drop side of lease document intake. A dropped file is parsed
 * and previewed first (nothing saved), then committed through
 * ScheduleIntake — the same write path the API uses.
 *
 * Between the two steps the upload is parked in a private intake directory
 * under a random name; the preview form carries that name as its token.
 */
class LeaseDocumentsController extends Controller
{
    private const INTAKE_PATH = 'private_uploads/lease-intake/';

    public function parse(Request $request, LeaseDocumentParser $parser, ScheduleIntake $intake)
    {
        $this->authorize('update', Order::class);

        $request->validate([
            'document' => 'required|file|mimes:pdf,xlsx|max:'.Helper::file_upload_max_size(),
        ]);

        $document = $request->file('document');
        $token = str_random(32).'.'.strtolower($document->getClientOriginalExtension());

        if (! Storage::exists(self::INTAKE_PATH)) {
            Storage::makeDirectory(self::INTAKE_PATH);
        }
        Storage::put(self::INTAKE_PATH.$token, file_get_contents($document));

        $parsed = $parser->parse(Storage::path(self::INTAKE_PATH.$token), $document->getClientOriginalName());

        if (($parsed['type'] ?? null) === null) {
            Storage::delete(self::INTAKE_PATH.$token);

            return redirect()->back()->with('error', $parsed['error'] ?? trans('admin/lease-intake/general.unrecognized_document'));
        }

        return view('lease-intake.preview', [
            'parsed' => $parsed,
            'warnings' => $intake->warnings($parsed),
            'token' => $token,
            'original_name' => $document->getClientOriginalName(),
        ]);
    }

    public function commit(Request $request, LeaseDocumentParser $parser, ScheduleIntake $intake): RedirectResponse
    {
        $this->authorize('update', Order::class);

        $data = $request->validate([
            'token' => ['required', 'string', 'regex:/^[A-Za-z0-9]+\.(pdf|xlsx)$/'],
            'original_name' => 'required|string|max:255',
            'schedule_ref' => 'required|string|max:32',
            'lessor' => 'nullable|string|max:191',
            'dated_as_of' => 'nullable|date',
            'term_start' => 'nullable|date',
            'term_end' => 'nullable|date',
            'term_months' => 'nullable|integer|min:1|max:240',
            'yearly_rental' => 'nullable|numeric|min:0',
            'stip_loss_value' => 'nullable|numeric|min:0',
            'cost_cap' => 'nullable|numeric|min:0',
            'lease_type' => 'nullable|string|max:64',
        ]);

        $relative = self::INTAKE_PATH.$data['token'];

        if (! Storage::exists($relative)) {
            return redirect()->route('procurement.index')->with('error', trans('admin/lease-intake/general.unrecognized_document'));
        }

        // Re-parse the parked file for the parts the form doesn't carry
        // (the per-serial lines), then lay the user's edits over the top.
        $parsed = $parser->parse(Storage::path($relative), $data['original_name']);
        $overrides = collect($data)->except(['token', 'original_name'])
            ->filter(fn ($v) => $v !== null && $v !== '')->all();
        $parsed = array_merge($parsed, $overrides);

        $upload = new UploadedFile(Storage::path($relative), $data['original_name'], null, null, true);

        try {
            $result = $intake->apply($parsed, $upload);
        } catch (\RuntimeException $e) {
            return redirect()->route('procurement.index')->with('error', $e->getMessage());
        } finally {
            Storage::delete($relative);
        }

        $message = trans('admin/lease-intake/general.committed_'.$result['action'], [
            'ref' => $parsed['schedule_ref'],
            'contract' => $result['contract']?->name,
        ]);

        return redirect(route('lease-schedules.show', $result['schedule']))
            ->with('success', $message)
            ->with('warning', $result['warnings'] ? implode(' ', $result['warnings']) : null);
    }
}
