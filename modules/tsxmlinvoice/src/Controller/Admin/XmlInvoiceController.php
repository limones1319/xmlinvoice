<?php

namespace PrestaShop\Module\Tsxmlinvoice\Controller\Admin;

use Address;
use Configuration;
use Country;
use Currency;
use Customer;
use DateTime;
use DOMDocument;
use Order;
use OrderInvoice;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tools;
use Validate;

class XmlInvoiceController extends FrameworkBundleAdminController
{
    public function generateAction(?int $id_order = null)
    {
        $this->assertValidToken();
        if (null === $id_order || $id_order <= 0) {
            $id_order = (int) Tools::getValue('id_order');
        }

        if ($id_order <= 0) {
            throw new NotFoundHttpException('Order ID is required.');
        }
        $this->denyAccessUnlessGranted('read', 'AdminOrders');

        $order = new Order($id_order);
        if (!Validate::isLoadedObject($order)) {
            throw new NotFoundHttpException('Order not found.');
        }

        $xml = $this->buildUbl($order);
        $filename = sprintf('invoice-%s.xml', preg_replace('/[^A-Za-z0-9_-]/', '', $order->reference));

        $response = new Response($xml);
        $response->headers->set('Content-Type', 'application/xml; charset=utf-8');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));

        return $response;
    }

    private function buildUbl(Order $order)
    {
        $currency = new Currency((int) $order->id_currency);
        $invoiceDate = $order->invoice_date ?: $order->date_add;
        $dueDate = $order->due_date ?? $invoiceDate;

        if ($order->hasInvoice()) {
            $invoices = $order->getInvoicesCollection();
            if ($invoices && $invoices->count()) {
                /** @var OrderInvoice $firstInvoice */
                foreach ($invoices as $invoiceEntity) {
                    $firstInvoice = $invoiceEntity;
                    break;
                }

                if (isset($firstInvoice) && $firstInvoice instanceof OrderInvoice) {
                    if (!empty($firstInvoice->date_add)) {
                        $invoiceDate = $firstInvoice->date_add;
                    }
                    if (property_exists($firstInvoice, 'due_date') && !empty($firstInvoice->due_date)) {
                        $dueDate = $firstInvoice->due_date;
                    }
                }
            }
        }

        $invoiceAddress = new Address((int) $order->id_address_invoice);
        $customer = new Customer((int) $order->id_customer);

        $shopName = Configuration::get('PS_SHOP_NAME');
        $supplierVat = Configuration::get('TS_XMLINVOICE_SUPPLIER_CIF');
        $supplierRegistration = Configuration::get('TS_XMLINVOICE_SUPPLIER_REGISTRATION');
        $supplierStreet = Configuration::get('TS_XMLINVOICE_SUPPLIER_STREET') ?: Configuration::get('PS_SHOP_ADDR1');
        $supplierCity = Configuration::get('TS_XMLINVOICE_SUPPLIER_CITY') ?: Configuration::get('PS_SHOP_CITY');
        $supplierPostcode = Configuration::get('TS_XMLINVOICE_SUPPLIER_POSTCODE') ?: Configuration::get('PS_SHOP_CODE');
        $supplierCountry = Configuration::get('TS_XMLINVOICE_SUPPLIER_COUNTRY') ?: Country::getIsoById((int) Configuration::get('PS_COUNTRY_DEFAULT'));
        $supplierStreet = $supplierStreet ?: '-';
        $supplierCity = $supplierCity ?: '-';
        $supplierPostcode = $supplierPostcode ?: '-';
        $supplierCountry = $supplierCountry ?: 'RO';

        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $invoice = $doc->createElementNS('urn:oasis:names:specification:ubl:schema:xsd:Invoice-2', 'Invoice');
        $invoice->setAttribute('xmlns:cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $invoice->setAttribute('xmlns:cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $doc->appendChild($invoice);

        $invoice->appendChild($this->createTextElement($doc, 'cbc:CustomizationID', 'urn:fdc:ro:gov:cie:cius-ro'));
        $invoice->appendChild($this->createTextElement($doc, 'cbc:ProfileID', 'urn:fdc:peppol.eu:poacc:billing:3.0'));
        $invoice->appendChild($this->createTextElement($doc, 'cbc:ID', $order->reference));
        $invoice->appendChild($this->createTextElement($doc, 'cbc:IssueDate', $this->formatDate($invoiceDate)));
        $invoice->appendChild($this->createTextElement($doc, 'cbc:DueDate', $this->formatDate($dueDate)));
        $invoice->appendChild($this->createTextElement($doc, 'cbc:InvoiceTypeCode', '380'));
        $invoice->appendChild($this->createTextElement($doc, 'cbc:DocumentCurrencyCode', $currency->iso_code));
        $invoice->appendChild($this->createTextElement($doc, 'cbc:BuyerReference', (string) $customer->id));

        $supplierParty = $doc->createElement('cac:AccountingSupplierParty');
        $supplierParty->appendChild($this->buildSupplierParty($doc, $shopName, $supplierVat, $supplierRegistration, $supplierStreet, $supplierCity, $supplierPostcode, $supplierCountry));
        $invoice->appendChild($supplierParty);

        $customerParty = $doc->createElement('cac:AccountingCustomerParty');
        $customerParty->appendChild($this->buildCustomerParty($doc, $invoiceAddress, $customer));
        $invoice->appendChild($customerParty);

        $taxData = $this->buildTaxData($order);

        $taxTotal = $doc->createElement('cac:TaxTotal');
        $taxTotal->appendChild($this->createAmountElement($doc, 'cbc:TaxAmount', $taxData['total'], $currency->iso_code));
        foreach ($taxData['subtotals'] as $subtotal) {
            $taxTotal->appendChild($this->buildTaxSubtotal($doc, $subtotal, $currency->iso_code));
        }
        $invoice->appendChild($taxTotal);

        $invoice->appendChild($this->buildMonetaryTotal($doc, $order, $currency->iso_code));

        $lineNumber = 1;
        foreach ($order->getOrderDetailList() as $detail) {
            $invoice->appendChild($this->buildInvoiceLine($doc, $detail, $currency->iso_code, $lineNumber++));
        }

        if ((float) $order->total_shipping_tax_excl > 0 || (float) $order->total_shipping_tax_incl > 0) {
            $invoice->appendChild($this->buildShippingLine($doc, $order, $currency->iso_code, $lineNumber++));
        }

        return $doc->saveXML();
    }

    private function assertValidToken()
    {
        $expectedToken = Tools::getAdminTokenLite('AdminOrders');
        $providedToken = (string) Tools::getValue('token');

        if ('' === $providedToken || !hash_equals($expectedToken, $providedToken)) {
            throw new AccessDeniedHttpException('Invalid security token.');
        }
    }

    private function buildSupplierParty(DOMDocument $doc, $name, $vat, $registration, $street, $city, $postcode, $countryCode)
    {
        $party = $doc->createElement('cac:Party');
        if ($vat) {
            $endpoint = $doc->createElement('cbc:EndpointID');
            $endpoint->appendChild($doc->createTextNode($vat));
            $endpoint->setAttribute('schemeID', 'VAT');
            $party->appendChild($endpoint);
        }

        $partyName = $doc->createElement('cac:PartyName');
        $partyName->appendChild($this->createTextElement($doc, 'cbc:Name', $name));
        $party->appendChild($partyName);

        $postalAddress = $doc->createElement('cac:PostalAddress');
        $postalAddress->appendChild($this->createTextElement($doc, 'cbc:StreetName', $street));
        $postalAddress->appendChild($this->createTextElement($doc, 'cbc:CityName', $city));
        $postalAddress->appendChild($this->createTextElement($doc, 'cbc:PostalZone', $postcode));
        $country = $doc->createElement('cac:Country');
        $country->appendChild($this->createTextElement($doc, 'cbc:IdentificationCode', $countryCode));
        $postalAddress->appendChild($country);
        $party->appendChild($postalAddress);

        $partyTaxScheme = $doc->createElement('cac:PartyTaxScheme');
        if ($vat) {
            $partyTaxScheme->appendChild($this->createTextElement($doc, 'cbc:CompanyID', $vat));
        }
        $taxScheme = $doc->createElement('cac:TaxScheme');
        $taxScheme->appendChild($this->createTextElement($doc, 'cbc:ID', 'VAT'));
        $partyTaxScheme->appendChild($taxScheme);
        $party->appendChild($partyTaxScheme);

        $partyLegalEntity = $doc->createElement('cac:PartyLegalEntity');
        $partyLegalEntity->appendChild($this->createTextElement($doc, 'cbc:RegistrationName', $name));
        if ($registration) {
            $partyLegalEntity->appendChild($this->createTextElement($doc, 'cbc:CompanyID', $registration));
        }
        $party->appendChild($partyLegalEntity);

        return $party;
    }

    private function buildCustomerParty(DOMDocument $doc, Address $address, Customer $customer)
    {
        $party = $doc->createElement('cac:Party');
        $partyName = $doc->createElement('cac:PartyName');
        $customerName = $address->company ?: trim($customer->firstname . ' ' . $customer->lastname);
        $partyName->appendChild($this->createTextElement($doc, 'cbc:Name', $customerName));
        $party->appendChild($partyName);

        $postalAddress = $doc->createElement('cac:PostalAddress');
        $postalAddress->appendChild($this->createTextElement($doc, 'cbc:StreetName', $address->address1));
        if (!empty($address->address2)) {
            $postalAddress->appendChild($this->createTextElement($doc, 'cbc:AdditionalStreetName', $address->address2));
        }
        $postalAddress->appendChild($this->createTextElement($doc, 'cbc:CityName', $address->city));
        $postalAddress->appendChild($this->createTextElement($doc, 'cbc:PostalZone', $address->postcode));
        $country = $doc->createElement('cac:Country');
        $country->appendChild($this->createTextElement($doc, 'cbc:IdentificationCode', Country::getIsoById((int) $address->id_country)));
        $postalAddress->appendChild($country);
        $party->appendChild($postalAddress);

        $partyLegalEntity = $doc->createElement('cac:PartyLegalEntity');
        $partyLegalEntity->appendChild($this->createTextElement($doc, 'cbc:RegistrationName', $customerName));
        if (!empty($address->vat_number)) {
            $partyTaxScheme = $doc->createElement('cac:PartyTaxScheme');
            $partyTaxScheme->appendChild($this->createTextElement($doc, 'cbc:CompanyID', $address->vat_number));
            $taxScheme = $doc->createElement('cac:TaxScheme');
            $taxScheme->appendChild($this->createTextElement($doc, 'cbc:ID', 'VAT'));
            $partyTaxScheme->appendChild($taxScheme);
            $party->appendChild($partyTaxScheme);
        }
        $party->appendChild($partyLegalEntity);

        return $party;
    }

    private function buildTaxData(Order $order)
    {
        $subtotals = [];
        $details = $order->getOrderDetailList();
        foreach ($details as $detail) {
            $rate = (float) $detail['tax_rate'];
            $lineTax = (float) $detail['total_price_tax_incl'] - (float) $detail['total_price_tax_excl'];
            if (!isset($subtotals[$rate])) {
                $subtotals[$rate] = [
                    'rate' => $rate,
                    'taxable' => 0.0,
                    'tax' => 0.0,
                ];
            }
            $subtotals[$rate]['taxable'] += (float) $detail['total_price_tax_excl'];
            $subtotals[$rate]['tax'] += $lineTax;
        }

        $totalTax = 0.0;
        foreach ($subtotals as $rate => $subtotal) {
            $totalTax += $subtotal['tax'];
        }

        $shippingExcl = (float) $order->total_shipping_tax_excl;
        $shippingIncl = (float) $order->total_shipping_tax_incl;
        if ($shippingExcl || $shippingIncl) {
            $shippingRate = (float) $order->carrier_tax_rate;
            $shippingTax = $shippingIncl - $shippingExcl;
            if (!isset($subtotals[$shippingRate])) {
                $subtotals[$shippingRate] = [
                    'rate' => $shippingRate,
                    'taxable' => 0.0,
                    'tax' => 0.0,
                ];
            }
            $subtotals[$shippingRate]['taxable'] += $shippingExcl;
            $subtotals[$shippingRate]['tax'] += $shippingTax;
            $totalTax += $shippingTax;
        }

        return [
            'total' => $totalTax,
            'subtotals' => $subtotals,
        ];
    }

    private function buildTaxSubtotal(DOMDocument $doc, array $subtotal, $currency)
    {
        $element = $doc->createElement('cac:TaxSubtotal');
        $element->appendChild($this->createAmountElement($doc, 'cbc:TaxableAmount', $subtotal['taxable'], $currency));
        $element->appendChild($this->createAmountElement($doc, 'cbc:TaxAmount', $subtotal['tax'], $currency));

        $category = $doc->createElement('cac:TaxCategory');
        $rate = (float) $subtotal['rate'];
        if ($rate > 0) {
            $category->appendChild($this->createTextElement($doc, 'cbc:ID', 'S'));
            $category->appendChild($this->createTextElement($doc, 'cbc:Percent', $this->formatAmount($rate)));
        } else {
            $category->appendChild($this->createTextElement($doc, 'cbc:ID', 'E'));
            $category->appendChild($this->createTextElement($doc, 'cbc:Percent', '0'));
            $category->appendChild($this->createTextElement($doc, 'cbc:TaxExemptionReasonCode', 'VATEX-EU-132-1'));
            $category->appendChild($this->createTextElement($doc, 'cbc:TaxExemptionReason', 'Exempt from VAT'));
        }
        $taxScheme = $doc->createElement('cac:TaxScheme');
        $taxScheme->appendChild($this->createTextElement($doc, 'cbc:ID', 'VAT'));
        $category->appendChild($taxScheme);

        $element->appendChild($category);

        return $element;
    }

    private function buildMonetaryTotal(DOMDocument $doc, Order $order, $currency)
    {
        $legalTotal = $doc->createElement('cac:LegalMonetaryTotal');
        $legalTotal->appendChild($this->createAmountElement($doc, 'cbc:LineExtensionAmount', (float) $order->total_products, $currency));
        $legalTotal->appendChild($this->createAmountElement($doc, 'cbc:TaxExclusiveAmount', (float) $order->total_paid_tax_excl, $currency));
        $legalTotal->appendChild($this->createAmountElement($doc, 'cbc:TaxInclusiveAmount', (float) $order->total_paid_tax_incl, $currency));
        $legalTotal->appendChild($this->createAmountElement($doc, 'cbc:PayableAmount', (float) $order->total_paid_tax_incl, $currency));

        return $legalTotal;
    }

    private function buildInvoiceLine(DOMDocument $doc, array $detail, $currency, $lineId)
    {
        $line = $doc->createElement('cac:InvoiceLine');
        $line->appendChild($this->createTextElement($doc, 'cbc:ID', (string) $lineId));
        $unitCode = !empty($detail['product_quantity_unit']) ? $detail['product_quantity_unit'] : 'EA';
        $line->appendChild($this->createAmountElement($doc, 'cbc:InvoicedQuantity', (float) $detail['product_quantity'], $unitCode, false));
        $line->appendChild($this->createAmountElement($doc, 'cbc:LineExtensionAmount', (float) $detail['total_price_tax_excl'], $currency));

        $item = $doc->createElement('cac:Item');
        $item->appendChild($this->createTextElement($doc, 'cbc:Name', $detail['product_name']));
        $classification = $doc->createElement('cac:ClassifiedTaxCategory');
        $rate = (float) $detail['tax_rate'];
        if ($rate > 0) {
            $classification->appendChild($this->createTextElement($doc, 'cbc:ID', 'S'));
            $classification->appendChild($this->createTextElement($doc, 'cbc:Percent', $this->formatAmount($rate)));
        } else {
            $classification->appendChild($this->createTextElement($doc, 'cbc:ID', 'E'));
            $classification->appendChild($this->createTextElement($doc, 'cbc:Percent', '0'));
            $classification->appendChild($this->createTextElement($doc, 'cbc:TaxExemptionReasonCode', 'VATEX-EU-132-1'));
            $classification->appendChild($this->createTextElement($doc, 'cbc:TaxExemptionReason', 'Exempt from VAT'));
        }
        $taxScheme = $doc->createElement('cac:TaxScheme');
        $taxScheme->appendChild($this->createTextElement($doc, 'cbc:ID', 'VAT'));
        $classification->appendChild($taxScheme);
        $item->appendChild($classification);
        $line->appendChild($item);

        $price = $doc->createElement('cac:Price');
        $unitPrice = (float) $detail['unit_price_tax_excl'];
        $price->appendChild($this->createAmountElement($doc, 'cbc:PriceAmount', $unitPrice, $currency));
        $price->appendChild($this->createAmountElement($doc, 'cbc:BaseQuantity', 1, $unitCode, false));
        $line->appendChild($price);

        return $line;
    }

    private function buildShippingLine(DOMDocument $doc, Order $order, $currency, $lineId)
    {
        $line = $doc->createElement('cac:InvoiceLine');
        $line->appendChild($this->createTextElement($doc, 'cbc:ID', (string) $lineId));
        $line->appendChild($this->createAmountElement($doc, 'cbc:InvoicedQuantity', 1, 'EA', false));
        $line->appendChild($this->createAmountElement($doc, 'cbc:LineExtensionAmount', (float) $order->total_shipping_tax_excl, $currency));

        $item = $doc->createElement('cac:Item');
        $item->appendChild($this->createTextElement($doc, 'cbc:Name', $order->getCarrierName() ?: $this->trans('Shipping')));

        $classification = $doc->createElement('cac:ClassifiedTaxCategory');
        $rate = (float) $order->carrier_tax_rate;
        if ($rate > 0) {
            $classification->appendChild($this->createTextElement($doc, 'cbc:ID', 'S'));
            $classification->appendChild($this->createTextElement($doc, 'cbc:Percent', $this->formatAmount($rate)));
        } else {
            $classification->appendChild($this->createTextElement($doc, 'cbc:ID', 'E'));
            $classification->appendChild($this->createTextElement($doc, 'cbc:Percent', '0'));
            $classification->appendChild($this->createTextElement($doc, 'cbc:TaxExemptionReasonCode', 'VATEX-EU-132-1'));
            $classification->appendChild($this->createTextElement($doc, 'cbc:TaxExemptionReason', 'Exempt from VAT'));
        }
        $taxScheme = $doc->createElement('cac:TaxScheme');
        $taxScheme->appendChild($this->createTextElement($doc, 'cbc:ID', 'VAT'));
        $classification->appendChild($taxScheme);
        $item->appendChild($classification);
        $line->appendChild($item);

        $price = $doc->createElement('cac:Price');
        $price->appendChild($this->createAmountElement($doc, 'cbc:PriceAmount', (float) $order->total_shipping_tax_excl, $currency));
        $price->appendChild($this->createAmountElement($doc, 'cbc:BaseQuantity', 1, 'EA', false));
        $line->appendChild($price);

        return $line;
    }

    private function createAmountElement(DOMDocument $doc, $name, $amount, $currency, $withCurrencyAttribute = true)
    {
        $formatted = $this->formatAmount($amount);
        $element = $doc->createElement($name, $formatted);
        if ($withCurrencyAttribute) {
            $element->setAttribute('currencyID', $currency);
        } else {
            $element->setAttribute('unitCode', $currency);
        }

        return $element;
    }

    private function createTextElement(DOMDocument $doc, $name, $value)
    {
        $element = $doc->createElement($name);
        $element->appendChild($doc->createTextNode((string) ($value ?? '')));

        return $element;
    }

    private function formatAmount($value)
    {
        return number_format((float) $value, 2, '.', '');
    }

    private function formatDate($date)
    {
        if (empty($date) || $date === '0000-00-00 00:00:00') {
            return (new DateTime())->format('Y-m-d');
        }

        try {
            return (new DateTime($date))->format('Y-m-d');
        } catch (\Exception $e) {
            return (new DateTime())->format('Y-m-d');
        }
    }
}