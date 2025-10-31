<?php

namespace PrestaShop\Module\Tsxmlinvoice\Controller\Admin;

use Address;
use Configuration;
use Context;
use Country;
use Currency;
use Customer;
use DateTime;
use DOMDocument;
use Order;
use OrderInvoice;
use State;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tools;
use Validate;

class XmlInvoiceController extends FrameworkBundleAdminController
{
    public function generate(int $orderId): Response
    {
        $id_order = (int) $orderId;
        if ($id_order <= 0) {
            throw new NotFoundHttpException('Order ID is required.');
        }

        $this->denyAccessUnlessGranted('read', 'AdminTsXmlInvoice');

        $order = new Order($id_order);
        if (!Validate::isLoadedObject($order)) {
            throw new NotFoundHttpException('Order not found.');
        }

        $xml = $this->buildUbl($order);
        $filename = sprintf('invoice-%s.xml', preg_replace('/[^A-Za-z0-9_-]/', '', (string)$order->reference));

        $response = new Response($xml);
        $response->headers->set('Content-Type', 'application/xml; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$filename.'"');

        return $response;
    }

    private function buildUbl(Order $order)
    {
        $currency = new Currency((int)$order->id_currency);
        $invoiceDate = $order->invoice_date ?: $order->date_add;
        $dueDate = $order->due_date ?? $invoiceDate;

        $invoiceNumber = null;
        $firstInvoice = null;
        if ($order->hasInvoice()) {
            $invoices = $order->getInvoicesCollection();
            if ($invoices && $invoices->count()) {
                /** @var OrderInvoice $inv */
                foreach ($invoices as $inv) {
                    $firstInvoice = $inv;
                    break;
                }
                if ($firstInvoice instanceof OrderInvoice) {
                    // Prefer formatted invoice number if available
                    if (method_exists($firstInvoice, 'getInvoiceNumberFormatted')) {
                        $invoiceNumber = $firstInvoice->getInvoiceNumberFormatted((int)$order->id_lang, Context::getContext()->language->id);
                    } else {
                        $invoiceNumber = (string)$firstInvoice->number;
                    }
                    // Prefer invoice's own date as IssueDate when available
                    if (!empty($firstInvoice->date_add)) {
                        $invoiceDate = $firstInvoice->date_add;
                    }
                }
            }
        }

        $context = Context::getContext();
        $shop = $context->shop;
        $shopAddress = null;
        if (method_exists($shop, 'getAddress')) {
            $shopAddress = $shop->getAddress();
        }
        if (!$shopAddress || !Validate::isLoadedObject($shopAddress)) {
            $shopAddress = null;
        }

        // VAT/CIF now parsed from PS_SHOP_DETAILS (fallback to old module fields)
        $vatCif = $this->getShopVatAndCif();
        $supplierVat = $vatCif['vat'];                // e.g., RO33913238
        $supplierRegistration = $vatCif['registration']; // numeric CIF e.g., 33913238

        $tradingName = (string) Configuration::get('PS_SHOP_NAME');
        $legalName = '';
        if ($shopAddress && !empty($shopAddress->company)) {
            $legalName = trim((string) $shopAddress->company);
        }
        if ($legalName === '') {
            $legalName = $tradingName;
        }

        // Supplier address with fallbacks from PS defaults
        $supplierStreet = trim((string) Configuration::get('TS_XMLINVOICE_SUPPLIER_STREET'));
        $supplierAdditionalStreet = '';
        $supplierCity = trim((string) Configuration::get('TS_XMLINVOICE_SUPPLIER_CITY'));
        $supplierPostcode = trim((string) Configuration::get('TS_XMLINVOICE_SUPPLIER_POSTCODE'));
        $supplierState = '';
        $supplierCountry = trim((string) Configuration::get('TS_XMLINVOICE_SUPPLIER_COUNTRY'));
        if ($shopAddress) {
            if ($supplierStreet === '' && !empty($shopAddress->address1)) {
                $supplierStreet = (string)$shopAddress->address1;
            }
            if ($supplierAdditionalStreet === '' && !empty($shopAddress->address2)) {
                $supplierAdditionalStreet = (string)$shopAddress->address2;
            }
            if ($supplierCity === '' && !empty($shopAddress->city)) {
                $supplierCity = (string)$shopAddress->city;
            }
            if ($supplierPostcode === '' && !empty($shopAddress->postcode)) {
                $supplierPostcode = (string)$shopAddress->postcode;
            }
            if ($supplierState === '' && $shopAddress->id_state) {
                $supplierState = (string) State::getNameById((int)$shopAddress->id_state);
            }
            if ($supplierCountry === '' && $shopAddress->id_country) {
                $supplierCountry = (string) Country::getIsoById((int)$shopAddress->id_country);
            }
        }
        if ($supplierStreet === '') {
            $supplierStreet = (string) Configuration::get('PS_SHOP_ADDR1');
        }
        if ($supplierAdditionalStreet === '') {
            $supplierAdditionalStreet = (string) Configuration::get('PS_SHOP_ADDR2');
        }
        if ($supplierCity === '') {
            $supplierCity = (string) Configuration::get('PS_SHOP_CITY');
        }
        if ($supplierPostcode === '') {
            $supplierPostcode = (string) Configuration::get('PS_SHOP_CODE');
        }
        if ($supplierCountry === '') {
            $supplierCountry = (string) Country::getIsoById((int) Configuration::get('PS_COUNTRY_DEFAULT'));
        }
        if ($supplierCountry === '') {
            $supplierCountry = 'RO';
        }

        $supplierAddressData = [
            'street' => $supplierStreet,
            'additionalStreet' => $supplierAdditionalStreet,
            'city' => $supplierCity,
            'postcode' => $supplierPostcode,
            'state' => $supplierState,
            'countryCode' => $supplierCountry,
        ];

        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = true;

        $invoice = $doc->createElementNS('urn:oasis:names:specification:ubl:schema:xsd:Invoice-2', 'Invoice');
        $doc->appendChild($invoice);
        $invoice->setAttribute('xmlns:cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $invoice->setAttribute('xmlns:cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');

        if ($invoiceNumber === null) {
            $invoiceNumber = (string)$order->reference;
        }
        $invoiceNumber = preg_replace('/^#/', '', $invoiceNumber);

        // Header
        $invoice->appendChild($this->createTextElement($doc, 'cbc:CustomizationID', 'urn:fdc:ro:gov:cie:cius-ro'));
        $invoice->appendChild($this->createTextElement($doc, 'cbc:ProfileID', 'urn:fdc:peppol.eu:poacc:billing:3.0'));
        $invoice->appendChild($this->createTextElement($doc, 'cbc:ID', $invoiceNumber));
        $invoice->appendChild($this->createTextElement($doc, 'cbc:IssueDate', $this->formatDate($invoiceDate)));
        $invoice->appendChild($this->createTextElement($doc, 'cbc:DueDate', $this->formatDate($dueDate)));
        $invoice->appendChild($this->createTextElement($doc, 'cbc:InvoiceTypeCode', '380'));
        $invoice->appendChild($this->createTextElement($doc, 'cbc:DocumentCurrencyCode', $currency->iso_code));

        // Parties
        $customer = new Customer((int)$order->id_customer);
        $invoiceAddress = new Address((int)$order->id_address_invoice);

        // One BuyerReference only
        $invoice->appendChild($this->createTextElement($doc, 'cbc:BuyerReference', (string)$customer->id));

        $supplierParty = $doc->createElement('cac:AccountingSupplierParty');
        $supplierParty->appendChild($this->buildSupplierParty($doc, $tradingName, $legalName, $supplierVat, $supplierRegistration, $supplierAddressData));
        $invoice->appendChild($supplierParty);

        $customerParty = $doc->createElement('cac:AccountingCustomerParty');
        $customerParty->appendChild($this->buildCustomerParty($doc, $invoiceAddress, $customer));
        $invoice->appendChild($customerParty);

        // Taxes
        $taxData = $this->buildTaxData($order);
        $taxTotal = $doc->createElement('cac:TaxTotal');
        $taxTotal->appendChild($this->createAmountElement($doc, 'cbc:TaxAmount', $taxData['total'], $currency->iso_code));
        foreach ($taxData['subtotals'] as $subtotal) {
            $taxTotal->appendChild($this->buildTaxSubtotal($doc, $subtotal, $currency->iso_code));
        }
        $invoice->appendChild($taxTotal);

        // Totals
        $legalMonetaryTotal = $doc->createElement('cac:LegalMonetaryTotal');
        $legalMonetaryTotal->appendChild($this->createAmountElement($doc, 'cbc:LineExtensionAmount', $taxData['base'], $currency->iso_code));
        $legalMonetaryTotal->appendChild($this->createAmountElement($doc, 'cbc:TaxExclusiveAmount', $taxData['base'], $currency->iso_code));
        $legalMonetaryTotal->appendChild($this->createAmountElement($doc, 'cbc:TaxInclusiveAmount', $taxData['total'] + $taxData['base'], $currency->iso_code));
        $legalMonetaryTotal->appendChild($this->createAmountElement($doc, 'cbc:PayableAmount', $taxData['total'] + $taxData['base'], $currency->iso_code));
        $invoice->appendChild($legalMonetaryTotal);

        // Lines
        $lineNumber = 1;
        foreach ($order->getOrderDetailList() as $detail) {
            $invoice->appendChild($this->buildInvoiceLine($doc, $detail, $currency->iso_code, $lineNumber++));
        }
        if ((float)$order->total_shipping_tax_excl > 0 || (float)$order->total_shipping_tax_incl > 0) {
            $invoice->appendChild($this->buildShippingLine($doc, $order, $currency->iso_code, $lineNumber++));
        }

        return $doc->saveXML();
    }

    private function buildSupplierParty(DOMDocument $doc, $tradingName, $legalName, $vat, $registration, array $address)
    {
        $party = $doc->createElement('cac:Party');

        if ($vat) {
            // EndpointID with VAT
            $endpoint = $doc->createElement('cbc:EndpointID');
            $endpoint->appendChild($doc->createTextNode($vat));
            $endpoint->setAttribute('schemeID', 'VAT');
            $party->appendChild($endpoint);
        }

        $displayName = $tradingName ?: $legalName;
        $partyNameValue = $legalName ?: $displayName;
        if ($partyNameValue !== '') {
            $partyName = $doc->createElement('cac:PartyName');
            $partyName->appendChild($this->createTextElement($doc, 'cbc:Name', $partyNameValue));
            $party->appendChild($partyName);
        }

        $postalAddress = $doc->createElement('cac:PostalAddress');
        $postalAddress->appendChild($this->createTextElement($doc, 'cbc:StreetName', $address['street'] ?? ''));
        if (!empty($address['additionalStreet'])) {
            $postalAddress->appendChild($this->createTextElement($doc, 'cbc:AdditionalStreetName', $address['additionalStreet']));
        }
        $postalAddress->appendChild($this->createTextElement($doc, 'cbc:CityName', $address['city'] ?? ''));
        $postalAddress->appendChild($this->createTextElement($doc, 'cbc:PostalZone', $address['postcode'] ?? ''));
        if (!empty($address['state'])) {
            $postalAddress->appendChild($this->createTextElement($doc, 'cbc:CountrySubentity', $address['state']));
        }
        $country = $doc->createElement('cac:Country');
        $country->appendChild($this->createTextElement($doc, 'cbc:IdentificationCode', $address['countryCode'] ?? ''));
        $postalAddress->appendChild($country);
        $party->appendChild($postalAddress);

        // VAT id in PartyTaxScheme/CompanyID (usually "RO{CIF}")
        $partyTaxScheme = $doc->createElement('cac:PartyTaxScheme');
        if ($vat) {
            $partyTaxScheme->appendChild($this->createTextElement($doc, 'cbc:CompanyID', $vat));
        }
        $taxScheme = $doc->createElement('cac:TaxScheme');
        $taxScheme->appendChild($this->createTextElement($doc, 'cbc:ID', 'VAT'));
        $partyTaxScheme->appendChild($taxScheme);
        $party->appendChild($partyTaxScheme);

        // Legal entity name and numeric CompanyID (CIF)
        $partyLegalEntity = $doc->createElement('cac:PartyLegalEntity');
        $partyLegalEntity->appendChild($this->createTextElement($doc, 'cbc:RegistrationName', $legalName ?: $displayName));
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
        if (!empty($address->id_state)) {
            $postalAddress->appendChild($this->createTextElement($doc, 'cbc:CountrySubentity', State::getNameById((int)$address->id_state)));
        }
        $postalAddress->appendChild($this->createTextElement($doc, 'cbc:PostalZone', $address->postcode));
        $country = $doc->createElement('cac:Country');
        $country->appendChild($this->createTextElement($doc, 'cbc:IdentificationCode', Country::getIsoById((int)$address->id_country)));
        $postalAddress->appendChild($country);
        $party->appendChild($postalAddress);

        // (Optional) add buyer VAT scheme here if you handle B2B with VAT numbers

        return $party;
    }

    private function buildTaxData(Order $order)
    {
        $subtotals = []; // key = percent (float)
        foreach ($order->getOrderDetailList() as $detail) {
            $rate = (float)$detail['tax_rate'];
            $excl = (float)$detail['total_price_tax_excl'];
            $incl = (float)$detail['total_price_tax_incl'];
            $tax  = max(0.0, $incl - $excl);

            if (!isset($subtotals[$rate])) {
                $subtotals[$rate] = ['taxable' => 0.0, 'tax' => 0.0, 'percent' => $rate];
            }
            $subtotals[$rate]['taxable'] += $excl;
            $subtotals[$rate]['tax']     += $tax;
        }

        // Shipping
        $shippingExcl = (float)$order->total_shipping_tax_excl;
        $shippingIncl = (float)$order->total_shipping_tax_incl;
        if ($shippingExcl > 0 || $shippingIncl > 0) {
            $shipRate = (float)$order->carrier_tax_rate;
            $shipTax  = max(0.0, $shippingIncl - $shippingExcl);
            if (!isset($subtotals[$shipRate])) {
                $subtotals[$shipRate] = ['taxable' => 0.0, 'tax' => 0.0, 'percent' => $shipRate];
            }
            $subtotals[$shipRate]['taxable'] += $shippingExcl;
            $subtotals[$shipRate]['tax']     += $shipTax;
        }

        $base  = 0.0;
        $total = 0.0;
        $out   = [];
        foreach ($subtotals as $rate => $st) {
            $base  += $st['taxable'];
            $total += $st['tax'];
            $out[] = [
                'taxable'   => $st['taxable'],
                'taxAmount' => $st['tax'],
                'percent'   => $st['percent'],
                'category'  => ($st['percent'] > 0.0) ? 'S' : 'Z',
            ];
        }

        return ['base' => $base, 'total' => $total, 'subtotals' => $out];
    }

    private function buildTaxSubtotal(DOMDocument $doc, array $subtotal, $currency)
    {
        $taxSubtotal = $doc->createElement('cac:TaxSubtotal');
        $taxSubtotal->appendChild($this->createAmountElement($doc, 'cbc:TaxableAmount', $subtotal['taxable'], $currency));
        $taxSubtotal->appendChild($this->createAmountElement($doc, 'cbc:TaxAmount', $subtotal['taxAmount'], $currency));

        $taxCategory = $doc->createElement('cac:TaxCategory');
        $taxCategory->appendChild($this->createTextElement($doc, 'cbc:ID', $subtotal['category']));
        $taxCategory->appendChild($this->createTextElement($doc, 'cbc:Percent', (string)$subtotal['percent']));
        // Optional exemption reason for zero-rated/scutite
        if ((float)$subtotal['percent'] == 0.0) {
            // $taxCategory->appendChild($this->createTextElement($doc, 'cbc:TaxExemptionReasonCode', 'VATEX-EU-...'));
            // $taxCategory->appendChild($this->createTextElement($doc, 'cbc:TaxExemptionReason', 'Exempt/Zero rated'));
        }
        $taxScheme = $doc->createElement('cac:TaxScheme');
        $taxScheme->appendChild($this->createTextElement($doc, 'cbc:ID', 'VAT'));
        $taxCategory->appendChild($taxScheme);

        $taxSubtotal->appendChild($taxCategory);
        return $taxSubtotal;
    }

    private function buildInvoiceLine(DOMDocument $doc, array $detail, string $currency, int $lineId)
    {
        $line = $doc->createElement('cac:InvoiceLine');
        $line->appendChild($this->createTextElement($doc, 'cbc:ID', (string)$lineId));

        $qty = (float)$detail['product_quantity'];
        $line->appendChild($this->createAmountElement($doc, 'cbc:InvoicedQuantity', $qty, 'C62', false));
        $line->appendChild($this->createAmountElement($doc, 'cbc:LineExtensionAmount', (float)$detail['total_price_tax_excl'], $currency));

        $item = $doc->createElement('cac:Item');
        $item->appendChild($this->createTextElement($doc, 'cbc:Name', (string)$detail['product_name']));

        $classification = $doc->createElement('cac:ClassifiedTaxCategory');
        $rate = (float)$detail['tax_rate'];
        if ($rate > 0.0) {
            $classification->appendChild($this->createTextElement($doc, 'cbc:ID', 'S'));
            $classification->appendChild($this->createTextElement($doc, 'cbc:Percent', $this->formatAmount($rate)));
        } else {
            $classification->appendChild($this->createTextElement($doc, 'cbc:ID', 'Z'));
            $classification->appendChild($this->createTextElement($doc, 'cbc:Percent', '0'));
        }
        $taxScheme = $doc->createElement('cac:TaxScheme');
        $taxScheme->appendChild($this->createTextElement($doc, 'cbc:ID', 'VAT'));
        $classification->appendChild($taxScheme);
        $item->appendChild($classification);
        $line->appendChild($item);

        $price = $doc->createElement('cac:Price');
        $unitPrice = (float)$detail['unit_price_tax_excl'];
        $price->appendChild($this->createAmountElement($doc, 'cbc:PriceAmount', $unitPrice, $currency));
        $price->appendChild($this->createAmountElement($doc, 'cbc:BaseQuantity', 1, 'C62', false));
        $line->appendChild($price);

        return $line;
    }

    private function buildShippingLine(DOMDocument $doc, Order $order, string $currency, int $lineId)
    {
        $line = $doc->createElement('cac:InvoiceLine');
        $line->appendChild($this->createTextElement($doc, 'cbc:ID', (string)$lineId));
        $line->appendChild($this->createAmountElement($doc, 'cbc:InvoicedQuantity', 1, 'C62', false));
        $line->appendChild($this->createAmountElement($doc, 'cbc:LineExtensionAmount', (float)$order->total_shipping_tax_excl, $currency));

        $item = $doc->createElement('cac:Item');
        $name = method_exists($order, 'getCarrierName') ? (string)$order->getCarrierName() : 'Shipping';
        $item->appendChild($this->createTextElement($doc, 'cbc:Name', $name));

        $classification = $doc->createElement('cac:ClassifiedTaxCategory');
        $rate = (float)$order->carrier_tax_rate;
        if ($rate > 0.0) {
            $classification->appendChild($this->createTextElement($doc, 'cbc:ID', 'S'));
            $classification->appendChild($this->createTextElement($doc, 'cbc:Percent', $this->formatAmount($rate)));
        } else {
            $classification->appendChild($this->createTextElement($doc, 'cbc:ID', 'Z'));
            $classification->appendChild($this->createTextElement($doc, 'cbc:Percent', '0'));
        }
        $taxScheme = $doc->createElement('cac:TaxScheme');
        $taxScheme->appendChild($this->createTextElement($doc, 'cbc:ID', 'VAT'));
        $classification->appendChild($taxScheme);
        $item->appendChild($classification);
        $line->appendChild($item);

        $price = $doc->createElement('cac:Price');
        $price->appendChild($this->createAmountElement($doc, 'cbc:PriceAmount', (float)$order->total_shipping_tax_excl, $currency));
        $price->appendChild($this->createAmountElement($doc, 'cbc:BaseQuantity', 1, 'C62', false));
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

    private function formatAmount($amount)
    {
        return number_format((float)$amount, 2, '.', '');
    }

    private function createTextElement(DOMDocument $doc, $name, $value)
    {
        return $doc->createElement($name, htmlspecialchars((string)$value, ENT_XML1 | ENT_COMPAT, 'UTF-8'));
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

    /**
     * Extract VAT (RO+number) and CIF/CompanyID (number) from PS_SHOP_DETAILS,
     * falling back to module configs if not found.
     */
    private function getShopVatAndCif(): array
    {
        $details = (string) Configuration::get('PS_SHOP_DETAILS');
        $vat = '';
        $cif = '';

        if (!empty($details)) {
            $text = strip_tags($details);
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = preg_replace('/\s+/', ' ', $text);

            if (preg_match('/\bRO\s*([0-9]{2,12})\b/i', $text, $m)) {
                $cif = $m[1];
                $vat = 'RO' . $cif;
            } elseif (preg_match('/\b(?:CIF|CUI|VAT|TVA)\s*[:#]?\s*(?:RO\s*)?([0-9]{2,12})\b/i', $text, $m)) {
                $cif = $m[1];
                $vat = 'RO' . $cif;
            }
        }

        if ($vat === '') {
            $vat = (string) Configuration::get('TS_XMLINVOICE_SUPPLIER_CIF');
        }
        if ($cif === '') {
            $cif = (string) Configuration::get('TS_XMLINVOICE_SUPPLIER_REGISTRATION');
        }

        if ($vat && !preg_match('/^RO/i', $vat) && preg_match('/^[0-9]{2,12}$/', $vat)) {
            $vat = 'RO' . $vat;
        }

        return ['vat' => (string)$vat, 'registration' => (string)$cif];
    }
}
