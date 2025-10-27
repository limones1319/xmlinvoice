<?php
declare(strict_types=1);

namespace PrestaShop\Module\Tsxmlinvoice\Service;

use Address;
use Configuration;
use Country;
use Currency;
use Customer;
use DOMDocument;
use DOMElement;
use Order;

class XmlInvoiceGenerator
{
    private const UNIT_CODE = 'EA';
    private const BASE_QUANTITY = 1.0;
    private const CUSTOMIZATION_ID = 'urn:cen.eu:en16931:2017#compliant#urn:efactura.mfinante.ro:CIUS-RO:1.0.1';
    private const PROFILE_ID = 'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0';
    private const INVOICE_TYPE_CODE = '380';

    private function money($amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    private function getInvoiceDisplayId(Order $order): string
    {
        $invoices = $order->getInvoicesCollection();
        if ($invoices && count($invoices)) {
            $lastInvoice = null;
            foreach ($invoices as $invoice) {
                $lastInvoice = $invoice;
            }

            if ($lastInvoice && method_exists($lastInvoice, 'getInvoiceNumberFormatted')) {
                $formatted = $lastInvoice->getInvoiceNumberFormatted((int) $order->id_lang, (int) $order->id_shop);

                return ltrim((string) $formatted, '#');
            }

            if ($lastInvoice && isset($lastInvoice->number) && $lastInvoice->number) {
                return 'TN' . str_pad((string) $lastInvoice->number, 6, '0', STR_PAD_LEFT);
            }
        }

        return (string) $order->reference;
    }

    private function add(DOMDocument $doc, DOMElement $parent, string $nsPrefix, string $name, ?string $value = null, array $attrs = []): DOMElement
    {
        static $nsMap = [
            '' => 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2',
            'cac' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
            'cbc' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
        ];
        $ns = '' === $nsPrefix ? $nsMap[''] : ($nsMap[$nsPrefix] ?? $nsMap['']);
        $el = $doc->createElementNS($ns, ($nsPrefix ? $nsPrefix . ':' : '') . $name);
        foreach ($attrs as $attrName => $attrValue) {
            $el->setAttribute($attrName, (string) $attrValue);
        }
        if (null !== $value) {
            $el->appendChild($doc->createTextNode($value));
        }
        $parent->appendChild($el);

        return $el;
    }

    private function stripChildNamespaceRedeclarations(DOMDocument $doc, DOMElement $root): void
    {
        $allElements = $doc->getElementsByTagName('*');

        foreach ($allElements as $element) {
            if ($element->isSameNode($root)) {
                continue;
            }

            $element->removeAttributeNS('http://www.w3.org/2000/xmlns/', 'cbc');
            $element->removeAttributeNS('http://www.w3.org/2000/xmlns/', 'cac');
        }
    }

    public function generateForOrder(Order $order): string
    {
        $invoiceId = $this->getInvoiceDisplayId($order);
        $issueDate = substr($order->invoice_date ?: $order->date_add, 0, 10);
        $dueDate = date('Y-m-d', strtotime($issueDate . ' +7 days'));

        $currency = new Currency((int) $order->id_currency);
        $docCurrency = strtoupper($currency->iso_code);

        $supplierName = Configuration::get('TSXML_SUPPLIER_NAME') ?: 'SC Veve Art SRL';
        $supplierCui = Configuration::get('TSXML_SUPPLIER_CUI') ?: '33913238';
        $supplierStreet = Configuration::get('TSXML_SUPPLIER_STREET') ?: 'Podul Inalt 16';
        $supplierCity = Configuration::get('TSXML_SUPPLIER_CITY') ?: 'Corbeanca';
        $supplierCounty = Configuration::get('TSXML_SUPPLIER_COUNTY_CODE') ?: 'RO-IF';
        $supplierCountry = 'RO';

        $invoiceAddress = new Address((int) $order->id_address_invoice);
        $customer = new Customer((int) $order->id_customer);
        $customerName = trim(($invoiceAddress->company ?: '') ?: ($invoiceAddress->firstname . ' ' . $invoiceAddress->lastname));
        $customerRegId = $invoiceAddress->vat_number ?: '0000000000000';
        $customerStreet = $invoiceAddress->address1;
        $customerCity = $invoiceAddress->city;
        $customerCountry = new Country((int) $invoiceAddress->id_country);
        $customerIso2 = strtoupper($customerCountry->iso_code);
        $customerCounty = Configuration::get('TSXML_DEFAULT_CUSTOMER_COUNTY') ?: 'RO-XX';

        $details = $order->getOrderDetailList();
        $lineNetSum = 0.0;
        $lineTaxSum = 0.0;

        foreach ($details as $detail) {
            $lineNetSum += (float) $detail['total_price_tax_excl'];
            $lineTaxSum += (float) $detail['total_price_tax_incl'] - (float) $detail['total_price_tax_excl'];
        }

        $taxableAmount = $lineNetSum;
        $taxAmount = $lineTaxSum;
        $taxCategoryId = $taxAmount > 0.0 ? 'S' : 'O';
        $taxExemptionReasonCode = 'O' === $taxCategoryId ? 'VATEX-EU-O' : null;

        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = false;

        $invoice = $doc->createElementNS('urn:oasis:names:specification:ubl:schema:xsd:Invoice-2', 'Invoice');
        $invoice->setAttribute('xmlns:cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $invoice->setAttribute('xmlns:cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $doc->appendChild($invoice);

        $this->add($doc, $invoice, 'cbc', 'CustomizationID', self::CUSTOMIZATION_ID);
        $this->add($doc, $invoice, 'cbc', 'ProfileID', self::PROFILE_ID);
        $this->add($doc, $invoice, 'cbc', 'ID', $invoiceId);
        $this->add($doc, $invoice, 'cbc', 'IssueDate', $issueDate);
        $this->add($doc, $invoice, 'cbc', 'DueDate', $dueDate);
        $this->add($doc, $invoice, 'cbc', 'InvoiceTypeCode', self::INVOICE_TYPE_CODE);
        $this->add($doc, $invoice, 'cbc', 'DocumentCurrencyCode', $docCurrency);

        $accountingSupplierParty = $this->add($doc, $invoice, 'cac', 'AccountingSupplierParty');
        $supplierParty = $this->add($doc, $accountingSupplierParty, 'cac', 'Party');
        $supplierPartyIdentification = $this->add($doc, $supplierParty, 'cac', 'PartyIdentification');
        $this->add($doc, $supplierPartyIdentification, 'cbc', 'ID', $supplierCui);
        $supplierPartyName = $this->add($doc, $supplierParty, 'cac', 'PartyName');
        $this->add($doc, $supplierPartyName, 'cbc', 'Name', $supplierName);
        $supplierPostalAddress = $this->add($doc, $supplierParty, 'cac', 'PostalAddress');
        $this->add($doc, $supplierPostalAddress, 'cbc', 'StreetName', $supplierStreet);
        $this->add($doc, $supplierPostalAddress, 'cbc', 'CityName', $supplierCity);
        $this->add($doc, $supplierPostalAddress, 'cbc', 'CountrySubentity', $supplierCounty);
        $supplierCountryElement = $this->add($doc, $supplierPostalAddress, 'cac', 'Country');
        $this->add($doc, $supplierCountryElement, 'cbc', 'IdentificationCode', $supplierCountry);
        $supplierPartyLegalEntity = $this->add($doc, $supplierParty, 'cac', 'PartyLegalEntity');
        $this->add($doc, $supplierPartyLegalEntity, 'cbc', 'RegistrationName', $supplierName);
        $this->add($doc, $supplierPartyLegalEntity, 'cbc', 'CompanyID', $supplierCui);

        $accountingCustomerParty = $this->add($doc, $invoice, 'cac', 'AccountingCustomerParty');
        $customerParty = $this->add($doc, $accountingCustomerParty, 'cac', 'Party');
        $customerPartyIdentification = $this->add($doc, $customerParty, 'cac', 'PartyIdentification');
        $this->add($doc, $customerPartyIdentification, 'cbc', 'ID', $customerRegId);
        $customerPartyName = $this->add($doc, $customerParty, 'cac', 'PartyName');
        $this->add($doc, $customerPartyName, 'cbc', 'Name', $customerName);
        $customerPostalAddress = $this->add($doc, $customerParty, 'cac', 'PostalAddress');
        $this->add($doc, $customerPostalAddress, 'cbc', 'StreetName', (string) $customerStreet);
        $this->add($doc, $customerPostalAddress, 'cbc', 'CityName', (string) $customerCity);
        $this->add($doc, $customerPostalAddress, 'cbc', 'CountrySubentity', $customerCounty);
        $customerCountryElement = $this->add($doc, $customerPostalAddress, 'cac', 'Country');
        $this->add($doc, $customerCountryElement, 'cbc', 'IdentificationCode', $customerIso2);
        $customerPartyLegalEntity = $this->add($doc, $customerParty, 'cac', 'PartyLegalEntity');
        $this->add($doc, $customerPartyLegalEntity, 'cbc', 'RegistrationName', $customerName);
        $this->add($doc, $customerPartyLegalEntity, 'cbc', 'CompanyID', $customerRegId);

        $taxTotal = $this->add($doc, $invoice, 'cac', 'TaxTotal');
        $this->add($doc, $taxTotal, 'cbc', 'TaxAmount', $this->money($taxAmount), ['currencyID' => $docCurrency]);
        $taxSubtotal = $this->add($doc, $taxTotal, 'cac', 'TaxSubtotal');
        $this->add($doc, $taxSubtotal, 'cbc', 'TaxableAmount', $this->money($taxableAmount), ['currencyID' => $docCurrency]);
        $this->add($doc, $taxSubtotal, 'cbc', 'TaxAmount', $this->money($taxAmount), ['currencyID' => $docCurrency]);
        $taxCategory = $this->add($doc, $taxSubtotal, 'cac', 'TaxCategory');
        $this->add($doc, $taxCategory, 'cbc', 'ID', $taxCategoryId);
        if ($taxExemptionReasonCode) {
            $this->add($doc, $taxCategory, 'cbc', 'TaxExemptionReasonCode', $taxExemptionReasonCode);
        }
        $taxScheme = $this->add($doc, $taxCategory, 'cac', 'TaxScheme');
        $this->add($doc, $taxScheme, 'cbc', 'ID', 'VAT');

        $legalMonetaryTotal = $this->add($doc, $invoice, 'cac', 'LegalMonetaryTotal');
        $this->add($doc, $legalMonetaryTotal, 'cbc', 'LineExtensionAmount', $this->money($lineNetSum), ['currencyID' => $docCurrency]);
        $this->add($doc, $legalMonetaryTotal, 'cbc', 'TaxExclusiveAmount', $this->money($lineNetSum), ['currencyID' => $docCurrency]);
        $this->add($doc, $legalMonetaryTotal, 'cbc', 'TaxInclusiveAmount', $this->money($lineNetSum + $taxAmount), ['currencyID' => $docCurrency]);
        $this->add($doc, $legalMonetaryTotal, 'cbc', 'PayableAmount', $this->money($lineNetSum + $taxAmount), ['currencyID' => $docCurrency]);

        $lineIdx = 0;
        foreach ($details as $detail) {
            ++$lineIdx;
            $qty = (float) $detail['product_quantity'];
            $name = (string) $detail['product_name'];
            $lineNet = (float) $detail['total_price_tax_excl'];
            $unitNet = $qty > 0 ? $lineNet / $qty : (float) $detail['unit_price_tax_excl'];
            $lineTax = (float) $detail['total_price_tax_incl'] - (float) $detail['total_price_tax_excl'];
            $hasTax = $lineTax > 0.0;

            $invoiceLine = $this->add($doc, $invoice, 'cac', 'InvoiceLine');
            $this->add($doc, $invoiceLine, 'cbc', 'ID', (string) $lineIdx);
            $this->add($doc, $invoiceLine, 'cbc', 'InvoicedQuantity', number_format($qty, 2, '.', ''), ['unitCode' => self::UNIT_CODE]);
            $this->add($doc, $invoiceLine, 'cbc', 'LineExtensionAmount', $this->money($lineNet), ['currencyID' => $docCurrency]);

            $item = $this->add($doc, $invoiceLine, 'cac', 'Item');
            $this->add($doc, $item, 'cbc', 'Name', $name);
            $classifiedTaxCategory = $this->add($doc, $item, 'cac', 'ClassifiedTaxCategory');
            $this->add($doc, $classifiedTaxCategory, 'cbc', 'ID', $hasTax ? 'S' : 'O');
            $classifiedTaxScheme = $this->add($doc, $classifiedTaxCategory, 'cac', 'TaxScheme');
            $this->add($doc, $classifiedTaxScheme, 'cbc', 'ID', 'VAT');

            $price = $this->add($doc, $invoiceLine, 'cac', 'Price');
            $this->add($doc, $price, 'cbc', 'PriceAmount', $this->money($unitNet), ['currencyID' => $docCurrency]);
            $this->add($doc, $price, 'cbc', 'BaseQuantity', number_format(self::BASE_QUANTITY, 2, '.', ''), ['unitCode' => self::UNIT_CODE]);
        }

        $this->stripChildNamespaceRedeclarations($doc, $invoice);

        return $doc->saveXML();
    }
}
