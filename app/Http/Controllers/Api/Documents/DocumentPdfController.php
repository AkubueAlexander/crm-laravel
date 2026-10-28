<?php

namespace App\Http\Controllers\Api\Documents;

use App\Domain\Documents\DocumentPdf;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

// {quote} / {invoice} are plain ids (no route-model binding): DocumentPdf looks them up with an explicit tenant predicate.
// GET only, read-only. ?download=1 sends the file as an attachment; otherwise it is shown inline (iframe preview).
final class DocumentPdfController extends Controller
{
    public function quote(Request $request, int $quote, DocumentPdf $pdf): Response
    {
        return $this->respond($request, $pdf->forQuote($request->user(), $quote));
    }

    public function invoice(Request $request, int $invoice, DocumentPdf $pdf): Response
    {
        return $this->respond($request, $pdf->forInvoice($request->user(), $invoice));
    }

    /** @param array{filename: string, content: string} $doc */
    private function respond(Request $request, array $doc): Response
    {
        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return response($doc['content'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition.'; filename="'.$doc['filename'].'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
