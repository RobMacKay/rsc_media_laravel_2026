<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\StudioSetting;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InvoiceDownloadController extends Controller
{
    /**
     * Download an invoice as a PDF.
     *
     * The PDF is always rendered light, whatever theme the client is using on
     * screen, because it is a document they will file or print.
     */
    public function __invoke(Request $request, Invoice $invoice): Response
    {
        abort_unless($invoice->team_id === $request->user()->current_team_id, 404);

        // A record is somebody else's document — an agency's remittance advice
        // filed for the studio's own books. Rendering it through this template
        // would dress it up as an invoice RSC Media issued, which it is not.
        // The remittance itself is served as an attachment instead.
        abort_if($invoice->record_only, 404);

        $settings = StudioSetting::current();

        return Pdf::loadView('pdf.invoice', [
            'invoice' => $invoice->load(['team', 'project']),
            'settings' => $settings,
            'terms' => $invoice->team->effectivePaymentTerms($settings),
        ])->setPaper('a4')->download($invoice->number.'.pdf');
    }
}
