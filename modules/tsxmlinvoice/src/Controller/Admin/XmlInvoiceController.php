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
    /**
     * Route target: /modules/tsxmlinvoice/generate/{id}
     * Serves the XML with proper headers.
     */
    public function generate(int $orderId): Response
    {
        $id_order = (int) $orderId;
        if ($id_order <= 0) {
            throw new NotFoundHttpException('Order ID is required.');
        }

        // Optional: secure with a permission if you have one configured
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

    /**
     * Backward-compat: returns only the XML string (no Response wrapper).
     * You can keep old code that might still call this.
     */
    public function generateInvoiceXml($orderId)
    {
        $id_order = (int) $orderId;
        $order = new Order($id_order);
        if (!\Validate::isLoadedObject($order)) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Order not found.');
        }
        return $this->buildUbl($order);
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
                foreach ($invoices as $inv) { $firstInvoice = $inv; break; }
                if ($firstInvoice instanceof OrderInvoice) {
                    $invoiceNumber = (string)$firstInvoice->getInvoiceNumberFormatted(Context::getContext()->language_id, $order->id_shop);
                    $invoiceDate = $firstInvoice->date_add ?: $invoiceDate;
                }
            }
        }

        $shopAddress = null;
        if (method_exists('Address', 'initialize')) {
            $shopAddress = Address::initialize((int)Configuration::get('PS_SHOP_ADDR1') ? (int) Configuration::get('PS_SHOP_ADDRESS_ID') : 0, true);
        } else {
            $shopAddress = new Address((int) Configuration::get('PS_SHOP_ADDRESS_ID'));
        }

        $vatCif = $this->getShopVatAndCif();
        $supplierVat = $vatCif['vat'];                // e.g. RO33913238
        $supplierRegistration = $vatCif['registration']; // numeric e.g. 33913238

        $tradingName = (string) Configuration::get('PS_SHOP_NAME');

        $legalName = '';
        if ($shopAddress && !empty($shopAddress->company)) {
            $legalName = trim((string) $shopAddress->company);
        }
        if ($legalName === '') {
            $legalName = $tradingName;
        }

        // Build supplier address
        $supplierStreet = trim((string) Configuration::get('TS_XMLINVOICE_SUPPLIER_STREET'));
        $supplierAdditionalStreet = trim((string) Configuration::get('TS_XMLINVOICE_SUPPLIER_STREET2'));
        $supplierCity = trim((string) Configuration::get('TS_XMLINVOICE_SUPPLIER_CITY'));
        $supplierPostcode = trim((string) Configuration::get('TS_XMLINVOICE_SUPPLIER_POSTCODE'));
        $supplierState = '';
        $supplierCountry = trim((string) Configuration::get('TS_XMLINVOICE_SUPPLIER_COUNTRY'));
        if ($shopAddress) {
            if ($supplierStreet === '' && !empty($shopAddress->address1)) {
                $supplierStreet = (string)$shopAddress->address1;       // address1
            }
            if ($supplierAdditionalStreet === '' && !empty($shopAddress->address2)) {
                $supplierAdditionalStreet = (string)$shopAddress->address2; // address2
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
            'street' => $supplierStreet,                 // address1
            'additionalStreet' => $supplierAdditionalStreet, // address2
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

        $invoice->appendChild($this->createTextElement($doc, 'cbc:BuyerReference', (string)$customer->id));

        $supplierParty = $doc->createElement('cac:AccountingSupplierParty');
        $supplierParty->appendChild($this->buildSupplierParty($doc, $tradingName, $legalName, $supplierVat, $supplierRegistration, $supplierAddressData));
        $invoice->appendChild($supplierParty);

        $customerParty = $doc->createElement('cac:AccountingCustomerParty');
        $customerParty->appendChild($this->buildCustomerParty($doc, $invoiceAddress, $customer));
        $invoice->appendChild($customerParty);

        // (Taxe + Totaluri + Linii) - neschimbate față de versiunea ta anterioară
        // --- Taxe ---
        $taxData = $this->buildTaxData($order);
        $taxTotal = $doc->createElement('cac:TaxTotal');
        $taxTotal->appendChild($this->createAmountElement($doc, 'cbc:TaxAmount', $taxData['total'], $currency->iso_code));
        foreach ($taxData['subtotals'] as $subtotal) {
            $taxTotal->appendChild($this->buildTaxSubtotal($doc, $subtotal, $currency->iso_code));
        }
        $invoice->appendChild($taxTotal);

        // --- Totaluri ---
        $legalMonetaryTotal = $doc->createElement('cac:LegalMonetaryTotal');
        $legalMonetaryTotal->appendChild($this->createAmountElement($doc, 'cbc:LineExtensionAmount', $taxData['taxable_total'], $currency->iso_code));
        $legalMonetaryTotal->appendChild($this->createAmountElement($doc, 'cbc:TaxExclusiveAmount', $taxData['taxable_total'], $currency->iso_code));
        $legalMonetaryTotal->appendChild($this->createAmountElement($doc, 'cbc:TaxInclusiveAmount', $taxData['inclusive_total'], $currency->iso_code));
        $legalMonetaryTotal->appendChild($this->createAmountElement($doc, 'cbc:PayableAmount', $taxData['inclusive_total'], $currency->iso_code));
        $invoice->appendChild($legalMonetaryTotal);

        // --- Linii ---
        foreach ($order->getProducts() as $detail) {
            $invoice->appendChild($this->buildInvoiceLine($doc, $detail, $currency->iso_code));
        }

        return $doc->saveXML();
    }

    private function buildSupplierParty(DOMDocument $doc, $tradingName, $legalName, $vat, $registration, array $address)
    {
        $party = $doc->createElement('cac:Party');

        if ($vat) {
            $endpoint = $doc->createElement('cbc:EndpointID');
            $endpoint->appendChild($doc->createTextNode($vat));
            $endpoint->setAttribute('schemeID', 'VAT');
            $party->appendChild($endpoint);
        }

        $displayName = $tradingName ?: $legalName;
        // Per cerință: PartyName/Name = address1 (linia "street" din address)
        $partyNameValue = isset($address['street']) && $address['street'] !== '' ? $address['street'] : ($legalName ?: $displayName);
        if ($partyNameValue !== '') {
            $partyName = $doc->createElement('cac:PartyName');
            $partyName->appendChild($this->createTextElement($doc, 'cbc:Name', $partyNameValue));
            $party->appendChild($partyName);
        }

        $postalAddress = $doc->createElement('cac:PostalAddress');
        // Per cerință: StreetName = address2; AdditionalStreetName = address1
        $postalAddress->appendChild($this->createTextElement($doc, 'cbc:StreetName', $address['additionalStreet'] ?? ''));
        if (!empty($address['street']) || !empty($address['additionalStreet'])) {
            $postalAddress->appendChild($this->createTextElement($doc, 'cbc:AdditionalStreetName', $address['street'] ?? ''));
        }
        $postalAddress->appendChild($this->createTextElement($doc, 'cbc:CityName', $address['city'] ?? ''));
        $postalAddress->appendChild($this->createTextElement($doc, 'cbc:PostalZone', $address['postcode'] ?? ''));
        if (!empty($address['state'])) {
            $postalAddress->appendChild($this->createTextElement($doc, 'cbc:CountrySubentity', $address['state']));
        }
        $country = $doc->createElement('cac:Country');
        // UBL cere codul țării ISO-3166 alpha-2 aici (RO), nu CIF
        $country->appendChild($this->createTextElement($doc, 'cbc:IdentificationCode', $address['countryCode'] ?? ''));
        $postalAddress->appendChild($country);
        $party->appendChild($postalAddress);

        // VAT în PartyTaxScheme/CompanyID (ex. "RO33913238")
        $partyTaxScheme = $doc->createElement('cac:PartyTaxScheme');
        if ($vat) {
            $partyTaxScheme->appendChild($this->createTextElement($doc, 'cbc:CompanyID', $vat));
        }
        $taxScheme = $doc->createElement('cac:TaxScheme');
        $taxScheme->appendChild($this->createTextElement($doc, 'cbc:ID', 'VAT'));
        $partyTaxScheme->appendChild($taxScheme);
        $party->appendChild($partyTaxScheme);

        // Entitatea legală: nume + CompanyID numeric (CIF fără "RO")
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

        return $party;
    }

    private function buildTaxData(Order $order): array
    {
        $subtotals = [];
        $totalTax = 0.0;

        foreach ($order->getProducts() as $detail) {
            $rate = (float)($detail['rate'] ?? 0.0);
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
            $rate = $shippingExcl > 0 ? (($shippingIncl - $shippingExcl) / $shippingExcl) * 100.0 : 0.0;
            $excl = $shippingExcl;
            $tax  = max(0.0, $shippingIncl - $shippingExcl);

            if (!isset($subtotals[$rate])) {
                $subtotals[$rate] = ['taxable' => 0.0, 'tax' => 0.0, 'percent' => $rate];
            }
            $subtotals[$rate]['taxable'] += $excl;
            $subtotals[$rate]['tax']     += $tax;
        }

        foreach ($subtotals as $arr) {
            $totalTax += $arr['tax'];
        }

        $taxable = 0.0;
        foreach ($subtotals as $arr) {
            $taxable += $arr['taxable'];
        }

        return [
            'total' => $this->formatAmount($totalTax),
            'taxable_total' => $this->formatAmount($taxable),
            'inclusive_total' => $this->formatAmount($taxable + $totalTax),
            'subtotals' => array_values($subtotals),
        ];
    }

    private function buildTaxSubtotal(DOMDocument $doc, array $subtotal, string $currencyIso)
    {
        $el = $doc->createElement('cac:TaxSubtotal');
        $el->appendChild($this->createAmountElement($doc, 'cbc:TaxableAmount', $subtotal['taxable'], $currencyIso));
        $el->appendChild($this->createAmountElement($doc, 'cbc:TaxAmount', $subtotal['tax'], $currencyIso));

        $taxCategory = $doc->createElement('cac:TaxCategory');
        $taxCategory->appendChild($this->createTextElement($doc, 'cbc:ID', $subtotal['percent'] > 0 ? 'S' : 'Z'));
        $taxCategory->appendChild($this->createTextElement($doc, 'cbc:Percent', (string)$subtotal['percent']));

        $taxScheme = $doc->createElement('cac:TaxScheme');
        $taxScheme->appendChild($this->createTextElement($doc, 'cbc:ID', 'VAT'));
        $taxCategory->appendChild($taxScheme);

        $el->appendChild($taxCategory);
        return $el;
    }

    private function createAmountElement(DOMDocument $doc, $name, $amount, $currency)
    {
        $el = $doc->createElement($name, $this->formatAmount($amount));
        $el->setAttribute('currencyID', $currency);
        return $el;
    }

    private function createTextElement(DOMDocument $doc, $name, $value)
    {
        return $doc->createElement($name, htmlspecialchars((string)$value, ENT_XML1 | ENT_COMPAT, 'UTF-8'));
    }

    private function formatDate($date)
    {
        if ($date instanceof DateTime) {
            return $date->format('Y-m-d');
        }
        $d = new DateTime($date);
        return $d->format('Y-m-d');
    }

    private function buildInvoiceLine(DOMDocument $doc, array $detail, string $currencyIso)
    {
        $line = $doc->createElement('cac:InvoiceLine');
        $line->appendChild($this->createTextElement($doc, 'cbc:ID', (string)$detail['id_order_detail']));
        $line->appendChild($this->createTextElement($doc, 'cbc:InvoicedQuantity', number_format((float)$detail['product_quantity'], 2, '.', '')));
        $line->appendChild($this->createAmountElement($doc, 'cbc:LineExtensionAmount', (float)$detail['total_price_tax_excl'], $currencyIso));

        $item = $doc->createElement('cac:Item');
        $item->appendChild($this->createTextElement($doc, 'cbc:Name', (string)$detail['product_name']));

        $taxCategory = $doc->createElement('cac:ClassifiedTaxCategory');
        $taxCategory->appendChild($this->createTextElement($doc, 'cbc:ID', ((float)($detail['rate'] ?? 0.0)) > 0 ? 'S' : 'Z'));
        $taxCategory->appendChild($this->createTextElement($doc, 'cbc:Percent', (string)((float)($detail['rate'] ?? 0.0))));

        $taxScheme = $doc->createElement('cac:TaxScheme');
        $taxScheme->appendChild($this->createTextElement($doc, 'cbc:ID', 'VAT'));
        $taxCategory->appendChild($taxScheme);

        $item->appendChild($taxCategory);
        $line->appendChild($item);

        $price = $doc->createElement('cac:Price');
        $price->appendChild($this->createTextElement($doc, 'cbc:PriceAmount', $this->formatAmount((float)$detail['unit_price_tax_excl'])));
        $price->appendChild($this->createTextElement($doc, 'cbc:BaseQuantity', number_format((float)$detail['product_quantity'], 2, '.', '')));
        $line->appendChild($price);

        return $line;
    }

    private function formatAmount($amount)
    {
        return number_format((float)$amount, 2, '.', '');
    }

    /**
     * Extract VAT (RO+number) and CIF (number) from PS_SHOP_DETAILS,
     * fallback to TS_XMLINVOICE_* if needed.
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

        // Normalize/sanitize
        if ($vat && !preg_match('/^RO/i', $vat) && preg_match('/^[0-9]{2,12}$/', $vat)) {
            $vat = 'RO' . $vat;
        }
        // avoid plain "RO"
        if (preg_match('/^RO\s*$/i', (string)$vat)) {
            $vat = '';
        }
        // derive CIF from VAT if needed
        if ($cif === '' && preg_match('/^RO\s*([0-9]{2,12})$/i', (string)$vat, $m2)) {
            $cif = $m2[1];
        }
        // derive VAT from CIF if VAT missing
        if ($vat === '' && $cif !== '') {
            $vat = 'RO' . $cif;
        }

        return ['vat' => (string)$vat, 'registration' => (string)$cif];
    }
}
