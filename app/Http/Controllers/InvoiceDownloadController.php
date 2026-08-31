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

        $settings = StudioSetting::current();

        return Pdf::loadView('pdf.invoice', [
            'invoice' => $invoice->load(['team', 'project']),
            'settings' => $settings,
            'terms' => $invoice->team->effectivePaymentTerms($settings),
        ])->setPaper('a4')->download($invoice->number.'.pdf');
    }
}
