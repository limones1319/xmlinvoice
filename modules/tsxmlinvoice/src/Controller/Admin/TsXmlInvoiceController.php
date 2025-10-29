<?php
namespace TsXmlInvoice\Controller\Admin;

use OrderInvoice;
use Validate;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class TsXmlInvoiceController extends Controller
{
    public function generateAction(Request $request, $invoiceId = null)
    {
        if ($invoiceId === null || $invoiceId === '') {
            return $this->render('@Modules/tsxmlinvoice/views/templates/admin/landing.html.twig', [
                'help' => 'Selectează o factură din admin și apasă „View XML Invoice”.',
            ]);
        }

        if (!ctype_digit((string) $invoiceId)) {
            $this->addFlash('error', 'Parametrul invoiceId trebuie să fie numeric.');
            return $this->redirectToRoute('admin_invoices_index');
        }

        $invoice = new OrderInvoice((int) $invoiceId);
        if (!Validate::isLoadedObject($invoice)) {
            $this->addFlash('error', sprintf('Factura #%s nu a fost găsită.', $invoiceId));
            return $this->redirectToRoute('admin_invoices_index');
        }

        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<Invoice id=\"{$invoice->id}\"></Invoice>\n";

        $response = new Response($xml);
        $response->headers->set('Content-Type', 'application/xml; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename=\"invoice-' . $invoice->id . '.xml\"');
        return $response;
    }
}
