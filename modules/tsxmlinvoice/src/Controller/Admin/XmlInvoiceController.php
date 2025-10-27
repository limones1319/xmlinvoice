<?php

namespace PrestaShop\Module\Tsxmlinvoice\Controller\Admin;

use Order;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Validate;

class XmlInvoiceController extends FrameworkBundleAdminController
{
    public function generateAction(Request $request): Response
    {
        $this->denyAccessUnlessGranted('read', 'AdminOrders');

        $id_order = (int) $request->query->get('id_order');
        if ($id_order <= 0) {
            throw new NotFoundHttpException('Order ID is required.');
        }

        $order = new Order($id_order);
        if (!Validate::isLoadedObject($order)) {
            throw new NotFoundHttpException('Order not found.');
        }

        /** @var \PrestaShop\Module\Tsxmlinvoice\Service\XmlInvoiceGenerator $generator */
        $generator = $this->get('tsxmlinvoice.generator');
        $xml = $generator->generateForOrder($order);

        $filename = sprintf('invoice-%s.xml', preg_replace('/[^A-Za-z0-9_-]/', '', $order->reference));

        return new Response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
        ]);
    }
}
