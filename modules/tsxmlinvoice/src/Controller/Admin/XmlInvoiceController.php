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
use State;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Validate;

class XmlInvoiceController extends FrameworkBundleAdminController
{
    /** Route target: /modules/tsxmlinvoice/generate/{orderId} */
    public function generate(int $orderId): Response
    {
        $id_order = (int) $orderId;
        if ($id_order <= 0) {
            throw new NotFoundHttpException('Order ID is required.');
        }

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

    /** Backward-compat: returns only the XML string (no Response wrapper). */
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
        if ($order->hasInvoice()) {
            $invoices = $order->getInvoicesCollection();
            if ($invoices && $invoices->count()) {
                foreach ($invoices as $inv) { $firstInvoice = $inv; break; }
                if (isset($firstInvoice) && $firstInvoice instanceof OrderInvoice) {
                    $invoiceNumber = (string)$firstInvoice->number;
                    $invoiceDate = $firstInvoice->date_add ?: $invoiceDate;
                }
            }
        }
        if ($invoiceNumber === null) { $invoiceNumber = (string)$order->reference; }
        $invoiceNumber = preg_replace('/^#/', '', $invoiceNumber);

        // Shop address
        $idShopAddr = (int) Configuration::get('PS_SHOP_ADDRESS_ID');
        $shopAddress = $idShopAddr ? new Address($idShopAddr) : null;

        // VAT (RO+număr) + CIF numeric (robust)
        $vatCif = $this->getShopVatAndCif();
        $supplierVat = $vatCif['vat'];                   // ex. RO33913238
        $supplierRegistration = $vatCif['registration']; // ex. 33913238

        $tradingName = (string) Configuration::get('PS_SHOP_NAME');

        // Legal name (folosim address1 dacă există, altfel PS_SHOP_NAME)
        $legalName = ($shopAddress && $shopAddress->address1) ? trim((string)$shopAddress->address1) : $tradingName;

        // Supplier address (preferă datele din adresa de magazin)
        $supplierStreet = $shopAddress ? (string)$shopAddress->address1 : (string) Configuration::get('PS_SHOP_ADDR1');
        $supplierAdditionalStreet = $shopAddress ? (string)$shopAddress->address2 : (string) Configuration::get('PS_SHOP_ADDR2');
        $supplierCity = $shopAddress ? (string)$shopAddress->city : (string) Configuration::get('PS_SHOP_CITY');
        $supplierPostcode = $shopAddress ? (string)$shopAddress->postcode : (string) Configuration::get('PS_SHOP_CODE');

        // ISO country
        $supplierCountryIso = $shopAddress && $shopAddress->id_country
            ? (string) Country::getIsoById((int)$shopAddress->id_country)
            : (string) Country::getIsoById((int) Configuration::get('PS_COUNTRY_DEFAULT'));
        if ($supplierCountryIso === '') { $supplierCountryIso = 'RO'; }

        // PS 1.7 nu are State::getIsoById; citim din obiectul State
        $supplierStateIso2  = $shopAddress && $shopAddress->id_state ? $this->getStateIso2ById((int)$shopAddress->id_state) : '';
        $supplierStateName  = $shopAddress && $shopAddress->id_state ? (string) State::getNameById((int)$shopAddress->id_state) : '';

        // Normalizare la ISO 3166-2:RO (RO-XX). Dacă lipsește, fallback (RO-IF).
        $supplierSubdivision = $this->ensureRoSubdivision(
            $supplierCountryIso,
            $this->normalizeRoSubdivision($supplierStateIso2, $supplierStateName),
            $supplierCity,
            $supplierPostcode,
            $this->getShopRoSubdivision() ?: 'RO-IF'
        );

        $supplierAddressData = [
            'street' => $supplierStreet,                      // address1 (ex: SC Veve Art SRL)
            'additionalStreet' => $supplierAdditionalStreet,  // address2 (ex: Podul Inalt 16)
            'city' => $supplierCity,
            'postcode' => $supplierPostcode,
            'state' => $supplierSubdivision,                  // garantat RO-XX dacă țara=RO
            'countryCode' => $supplierCountryIso,
        ];

        // ---------- UBL ----------
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = true;

        $invoice = $doc->createElementNS('urn:oasis:names:specification:ubl:schema:xsd:Invoice-2', 'Invoice');
        $doc->appendChild($invoice);
        $invoice->setAttribute('xmlns:cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $invoice->setAttribute('xmlns:cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');

        // Header (RO_CIUS strict – BR-RO-001)
        $invoice->appendChild($this->createTextElement($doc, 'cbc:CustomizationID', 'urn:cen.eu:en16931:2017#compliant#urn:efactura.mfinante.ro:CIUS-RO:1.0.1'));
        $invoice->appendChild($this->createTextElement($doc, 'cbc:ProfileID', 'urn:fdc:peppol.eu:poacc:billing:3.0'));
        $invoice->appendChild($this->createTextElement($doc, 'cbc:ID', $invoiceNumber));
        $invoice->appendChild($this->createTextElement($doc, 'cbc:IssueDate', $this->formatDate($invoiceDate)));
        $invoice->appendChild($this->createTextElement($doc, 'cbc:DueDate', $this->formatDate($dueDate)));
        $invoice->appendChild($this->createTextElement($doc, 'cbc:InvoiceTypeCode', '380'));
        $invoice->appendChild($this->createTextElement($doc, 'cbc:DocumentCurrencyCode', $currency->iso_code));

        // Buyer reference & parties
        $customer = new Customer((int)$order->id_customer);
        $invoiceAddress = new Address((int)$order->id_address_invoice);
        $invoice->appendChild($this->createTextElement($doc, 'cbc:BuyerReference', (string)$customer->id));

        $supplierParty = $doc->createElement('cac:AccountingSupplierParty');
        $supplierParty->appendChild($this->buildSupplierParty($doc, $tradingName, $legalName, $supplierVat, $supplierRegistration, $supplierAddressData));
        $invoice->appendChild($supplierParty);

        // Buyer: fallback la subdiviziunea determinată pentru Seller (ca să satisfacă BR-RO-111)
        $customerParty = $doc->createElement('cac:AccountingCustomerParty');
        $customerParty->appendChild($this->buildCustomerParty($doc, $invoiceAddress, $customer, $supplierAddressData['state']));
        $invoice->appendChild($customerParty);

        // Taxe
        $taxData = $this->buildTaxData($order);
        $taxTotal = $doc->createElement('cac:TaxTotal');
        $taxTotal->appendChild($this->createAmountElement($doc, 'cbc:TaxAmount', $taxData['total'], $currency->iso_code));
        foreach ($taxData['subtotals'] as $subtotal) {
            $taxTotal->appendChild($this->buildTaxSubtotal($doc, $subtotal, $currency->iso_code));
        }
        $invoice->appendChild($taxTotal);

        // Totaluri
        $legalMonetaryTotal = $doc->createElement('cac:LegalMonetaryTotal');
        $legalMonetaryTotal->appendChild($this->createAmountElement($doc, 'cbc:LineExtensionAmount', $taxData['taxable_total'], $currency->iso_code));
        $legalMonetaryTotal->appendChild($this->createAmountElement($doc, 'cbc:TaxExclusiveAmount', $taxData['taxable_total'], $currency->iso_code));
        $legalMonetaryTotal->appendChild($this->createAmountElement($doc, 'cbc:TaxInclusiveAmount', $taxData['inclusive_total'], $currency->iso_code));
        $legalMonetaryTotal->appendChild($this->createAmountElement($doc, 'cbc:PayableAmount', $taxData['inclusive_total'], $currency->iso_code));
        $invoice->appendChild($legalMonetaryTotal);

        // Linii
        $lineNo = 1;
        foreach ($order->getProducts() as $detail) {
            $invoice->appendChild($this->buildInvoiceLine($doc, $detail, $currency->iso_code, $lineNo++));
        }

        return $doc->saveXML();
    }

    private function buildSupplierParty(DOMDocument $doc, $tradingName, $legalName, $vat, $registration, array $address)
    {
        $party = $doc->createElement('cac:Party');

        // PartyName/Name = address1 (SC Veve Art SRL)
        $partyNameVal = $address['street'] ?: ($legalName ?: $tradingName);
        if ($partyNameVal !== '') {
            $partyName = $doc->createElement('cac:PartyName');
            $partyName->appendChild($this->createTextElement($doc, 'cbc:Name', $partyNameVal));
            $party->appendChild($partyName);
        }

        // PostalAddress: StreetName = address2; AdditionalStreetName = address1
        $postalAddress = $doc->createElement('cac:PostalAddress');
        $postalAddress->appendChild($this->createTextElement($doc, 'cbc:StreetName', $address['additionalStreet'] ?? ''));
        if (!empty($address['street']) || !empty($address['additionalStreet'])) {
            $postalAddress->appendChild($this->createTextElement($doc, 'cbc:AdditionalStreetName', $address['street'] ?? ''));
        }
        $postalAddress->appendChild($this->createTextElement($doc, 'cbc:CityName', $address['city'] ?? ''));
        $postalAddress->appendChild($this->createTextElement($doc, 'cbc:PostalZone', $address['postcode'] ?? ''));

        // CountrySubentity – obligatoriu cod ISO 3166-2:RO dacă țara=RO
        if (strtoupper((string)$address['countryCode']) === 'RO') {
            $postalAddress->appendChild($this->createTextElement($doc, 'cbc:CountrySubentity', $address['state']));
        }

        $country = $doc->createElement('cac:Country');
        $country->appendChild($this->createTextElement($doc, 'cbc:IdentificationCode', $address['countryCode'] ?? ''));
        $postalAddress->appendChild($country);
        $party->appendChild($postalAddress);

        // PartyTaxScheme: VAT id (BT-31) -> cbc:CompanyID
        if (!$vat && $registration) {
            $digits = preg_replace('/\D+/', '', (string)$registration);
            if ($digits !== '') {
                $vat = 'RO' . $digits;
            }
        }
        $partyTaxScheme = $doc->createElement('cac:PartyTaxScheme');
        if ($vat) {
            $partyTaxScheme->appendChild($this->createTextElement($doc, 'cbc:CompanyID', $vat)); // ex. RO33913238
        }
        $taxScheme = $doc->createElement('cac:TaxScheme');
        $taxScheme->appendChild($this->createTextElement($doc, 'cbc:ID', 'VAT'));
        $partyTaxScheme->appendChild($taxScheme);
        $party->appendChild($partyTaxScheme);

        // PartyLegalEntity: RegistrationName = address1; CompanyID = CIF numeric (BT-30)
        $partyLegalEntity = $doc->createElement('cac:PartyLegalEntity');
        $partyLegalEntity->appendChild($this->createTextElement($doc, 'cbc:RegistrationName', $address['street'] ?: ($legalName ?: $tradingName)));

        $companyIdNumeric = $registration;
        if (!$companyIdNumeric && $vat && preg_match('/^RO\s*([0-9]{2,12})$/i', (string)$vat, $m)) {
            $companyIdNumeric = $m[1];
        }
        if ($companyIdNumeric) {
            $companyIdNumeric = preg_replace('/\D+/', '', (string)$companyIdNumeric);
            if ($companyIdNumeric !== '') {
                $partyLegalEntity->appendChild($this->createTextElement($doc, 'cbc:CompanyID', $companyIdNumeric)); // ex. 33913238
            }
        }
        $party->appendChild($partyLegalEntity);

        return $party;
    }

    private function buildCustomerParty(DOMDocument $doc, Address $address, Customer $customer, string $fallbackRoSubdivision = '')
    {
        $party = $doc->createElement('cac:Party');

        $buyerName = $address->company ?: trim($customer->firstname . ' ' . $customer->lastname);
        $partyName = $doc->createElement('cac:PartyName');
        $partyName->appendChild($this->createTextElement($doc, 'cbc:Name', $buyerName));
        $party->appendChild($partyName);

        $buyerCountryIso = Country::getIsoById((int)$address->id_country);
        $stateIso2 = $address->id_state ? $this->getStateIso2ById((int)$address->id_state) : '';
        $stateName = $address->id_state ? (string) State::getNameById((int)$address->id_state) : '';

        $postalAddress = $doc->createElement('cac:PostalAddress');
        $postalAddress->appendChild($this->createTextElement($doc, 'cbc:StreetName', $address->address1));
        if (!empty($address->address2)) {
            $postalAddress->appendChild($this->createTextElement($doc, 'cbc:AdditionalStreetName', $address->address2));
        }
        $postalAddress->appendChild($this->createTextElement($doc, 'cbc:CityName', $address->city));

        // !!! ORDINE UBL corectă: CityName → PostalZone → CountrySubentity → Country
        $postalAddress->appendChild($this->createTextElement($doc, 'cbc:PostalZone', $address->postcode));

        if ($buyerCountryIso === 'RO') {
            $norm = $this->normalizeRoSubdivision($stateIso2, $stateName);
            $norm = $this->ensureRoSubdivision('RO', $norm, $address->city, $address->postcode, $fallbackRoSubdivision ?: 'RO-IF');
            $postalAddress->appendChild($this->createTextElement($doc, 'cbc:CountrySubentity', $norm));
        } elseif (!empty($address->id_state)) {
            $postalAddress->appendChild($this->createTextElement($doc, 'cbc:CountrySubentity', $stateName));
        }

        $country = $doc->createElement('cac:Country');
        $country->appendChild($this->createTextElement($doc, 'cbc:IdentificationCode', $buyerCountryIso));
        $postalAddress->appendChild($country);
        $party->appendChild($postalAddress);

        // BuyerLegalEntity/RegistrationName (BR-07)
        $ple = $doc->createElement('cac:PartyLegalEntity');
        $ple->appendChild($this->createTextElement($doc, 'cbc:RegistrationName', $buyerName));

        // BR-RO-120: Buyer IDs (VAT/CNP/CUI)
        $buyerVat = trim((string)$address->vat_number);
        $buyerDni = trim((string)$address->dni); // poate fi CNP

        if ($buyerVat !== '') {
            if ($buyerCountryIso === 'RO' && preg_match('/^[0-9]{2,12}$/', $buyerVat)) {
                $buyerVat = 'RO' . $buyerVat;
            }
            $pts = $doc->createElement('cac:PartyTaxScheme');
            $pts->appendChild($this->createTextElement($doc, 'cbc:CompanyID', $buyerVat));
            $ts = $doc->createElement('cac:TaxScheme');
            $ts->appendChild($this->createTextElement($doc, 'cbc:ID', 'VAT'));
            $pts->appendChild($ts);
            $party->appendChild($pts);
        }

        $buyerCompanyId = '';
        if ($address->company) {
            if ($buyerCountryIso === 'RO' && preg_match('/^RO\s*([0-9]{2,12})$/i', $buyerVat, $m)) {
                $buyerCompanyId = $m[1];
            }
        } else {
            if ($buyerCountryIso === 'RO') {
                if (preg_match('/^[0-9]{13}$/', $buyerDni)) {
                    $buyerCompanyId = $buyerDni;
                } else {
                    $buyerCompanyId = '0000000000000';
                }
            }
        }
        if ($buyerCompanyId !== '') {
            $ple->appendChild($this->createTextElement($doc, 'cbc:CompanyID', $buyerCompanyId));
        }

        $party->appendChild($ple);

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

        // Shipping (dacă folosești linie separată)
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

        foreach ($subtotals as $arr) { $totalTax += $arr['tax']; }
        $taxable = 0.0; foreach ($subtotals as $arr) { $taxable += $arr['taxable']; }

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

    private function buildInvoiceLine(DOMDocument $doc, array $detail, string $currencyIso, int $lineNo)
    {
        $line = $doc->createElement('cac:InvoiceLine');
        $line->appendChild($this->createTextElement($doc, 'cbc:ID', (string)$lineNo));

        // BR-23: unitCode obligatoriu pe InvoicedQuantity (ex. C62 – UN/CEFACT)
        $line->appendChild($this->createQuantityElement($doc, 'cbc:InvoicedQuantity', (float)$detail['product_quantity'], 'C62'));
        $line->appendChild($this->createAmountElement($doc, 'cbc:LineExtensionAmount', (float)$detail['total_price_tax_excl'], $currencyIso));

        $item = $doc->createElement('cac:Item');
        $item->appendChild($this->createTextElement($doc, 'cbc:Name', (string)$detail['product_name']));

        $taxCategory = $doc->createElement('cac:ClassifiedTaxCategory');
        $rate = (float)($detail['rate'] ?? 0.0);
        $taxCategory->appendChild($this->createTextElement($doc, 'cbc:ID', $rate > 0 ? 'S' : 'Z'));
        $taxCategory->appendChild($this->createTextElement($doc, 'cbc:Percent', (string)$rate));
        $taxScheme = $doc->createElement('cac:TaxScheme');
        $taxScheme->appendChild($this->createTextElement($doc, 'cbc:ID', 'VAT'));
        $taxCategory->appendChild($taxScheme);

        $item->appendChild($taxCategory);
        $line->appendChild($item);

        $price = $doc->createElement('cac:Price');
        $price->appendChild($this->createAmountElement($doc, 'cbc:PriceAmount', (float)$detail['unit_price_tax_excl'], $currencyIso));
        $price->appendChild($this->createQuantityElement($doc, 'cbc:BaseQuantity', (float)$detail['product_quantity'], 'C62'));
        $line->appendChild($price);

        return $line;
    }

    private function createAmountElement(DOMDocument $doc, $name, $amount, $currency)
    {
        $el = $doc->createElement($name, $this->formatAmount($amount));
        $el->setAttribute('currencyID', $currency);
        return $el;
    }

    private function createQuantityElement(DOMDocument $doc, $name, $qty, $unitCode)
    {
        $el = $doc->createElement($name, number_format((float)$qty, 2, '.', ''));
        if ($unitCode) { $el->setAttribute('unitCode', $unitCode); }
        return $el;
    }

    private function createTextElement(DOMDocument $doc, $name, $value)
    {
        return $doc->createElement($name, htmlspecialchars((string)$value, ENT_XML1 | ENT_COMPAT, 'UTF-8'));
    }

    private function formatDate($date)
    {
        if ($date instanceof DateTime) { return $date->format('Y-m-d'); }
        $d = new DateTime($date);
        return $d->format('Y-m-d');
    }

    private function formatAmount($amount)
    {
        return number_format((float)$amount, 2, '.', '');
    }

    /** Întoarce codul ISO (2 litere) al state-ului PS (ex. IF, B) sau '' */
    private function getStateIso2ById(int $id_state): string
    {
        if ($id_state <= 0) { return ''; }
        $st = new State($id_state);
        if (!Validate::isLoadedObject($st) || empty($st->iso_code)) { return ''; }
        return strtoupper((string)$st->iso_code);
    }

    /**
     * Normalizează la forma ISO 3166-2:RO (RO-XX) dintr-un cod de tip "IF"/"B" sau din numele județului.
     */
    private function normalizeRoSubdivision(?string $stateIso2, ?string $stateName): string
    {
        $iso = strtoupper(trim((string)$stateIso2));
        if ($iso !== '') {
            if (strpos($iso, 'RO-') !== 0) { $iso = 'RO-' . $iso; }
            return $iso;
        }
        $name = strtoupper(trim((string)$stateName));
        if ($name === '') { return ''; }

        $map = [
            'ALBA'=>'RO-AB','ARAD'=>'RO-AR','ARGES'=>'RO-AG','ARGEȘ'=>'RO-AG','BACAU'=>'RO-BC','BACĂU'=>'RO-BC','BIHOR'=>'RO-BH',
            'BISTRITA-NASAUD'=>'RO-BN','BISTRIȚA-NĂSĂUD'=>'RO-BN','BOTOSANI'=>'RO-BT','BOTOȘANI'=>'RO-BT','BRASOV'=>'RO-BV','BRAȘOV'=>'RO-BV',
            'BRAILA'=>'RO-BR','BRĂILA'=>'RO-BR','BUCURESTI'=>'RO-B','BUCUREȘTI'=>'RO-B',
            'BUZAU'=>'RO-BZ','BUZĂU'=>'RO-BZ','CALARASI'=>'RO-CL','CĂLĂRAȘI'=>'RO-CL','CARAS-SEVERIN'=>'RO-CS','CARAȘ-SEVERIN'=>'RO-CS',
            'CLUJ'=>'RO-CJ','CONSTANTA'=>'RO-CT','CONSTANȚA'=>'RO-CT','COVASNA'=>'RO-CV','DAMBOVITA'=>'RO-DB','DÂMBOVIȚA'=>'RO-DB',
            'DOLJ'=>'RO-DJ','GALATI'=>'RO-GL','GALAȚI'=>'RO-GL','GIURGIU'=>'RO-GR','GORJ'=>'RO-GJ','HARGHITA'=>'RO-HR','HUNEDOARA'=>'RO-HD',
            'IALOMITA'=>'RO-IL','IALOMIȚA'=>'RO-IL','IASI'=>'RO-IS','IAȘI'=>'RO-IS','ILFOV'=>'RO-IF',
            'MARAMURES'=>'RO-MM','MARAMUREȘ'=>'RO-MM','MEHEDINTI'=>'RO-MH','MEHEDINȚI'=>'RO-MH','MURES'=>'RO-MS','MUREȘ'=>'RO-MS',
            'NEAMT'=>'RO-NT','NEAMȚ'=>'RO-NT','OLT'=>'RO-OT','PRAHOVA'=>'RO-PH','SALAJ'=>'RO-SJ','SĂLAJ'=>'RO-SJ','SATU MARE'=>'RO-SM',
            'SIBIU'=>'RO-SB','SUCEAVA'=>'RO-SV','TELEORMAN'=>'RO-TR','TIMIS'=>'RO-TM','TIMIȘ'=>'RO-TM','TULCEA'=>'RO-TL',
            'VALCEA'=>'RO-VL','VÂLCEA'=>'RO-VL','VASLUI'=>'RO-VS','VRANCEA'=>'RO-VN',
        ];
        return isset($map[$name]) ? $map[$name] : '';
    }

    /**
     * Se asigură că, dacă țara este RO, returnează întotdeauna o subdiviziune validă ISO 3166-2:RO.
     * Heuristică simplă: dacă orașul conține „București”, folosim RO-B; altfel, fallback.
     */
    private function ensureRoSubdivision(string $countryIso, ?string $candidate, ?string $city, ?string $postcode, string $fallback = 'RO-IF'): string
    {
        if (strtoupper($countryIso) !== 'RO') {
            return (string)$candidate;
        }
        $cand = trim((string)$candidate);
        if ($cand !== '') {
            return $cand;
        }
        $city = (string)$city;
        if (preg_match('/\b(BUCURE[ȘS]TI|BUCHAREST)\b/i', $city)) {
            return 'RO-B';
        }
        return $fallback; // ex. RO-IF
    }

    /** Returnează județul magazinului în format ISO 3166-2:RO (ex. RO-IF) sau '' */
    private function getShopRoSubdivision(): string
    {
        $idShopAddr = (int) Configuration::get('PS_SHOP_ADDRESS_ID');
        if (!$idShopAddr) { return ''; }
        $shopAddress = new Address($idShopAddr);
        if (!Validate::isLoadedObject($shopAddress)) { return ''; }
        $countryIso = $shopAddress->id_country ? Country::getIsoById((int)$shopAddress->id_country) : '';
        if ($countryIso !== 'RO') { return ''; }
        $stateIso2  = $shopAddress->id_state ? $this->getStateIso2ById((int)$shopAddress->id_state) : '';
        $stateName  = $shopAddress->id_state ? (string) State::getNameById((int)$shopAddress->id_state) : '';
        return $this->normalizeRoSubdivision($stateIso2, $stateName);
    }

    /**
     * Extract VAT (RO+number) and CIF (number) from multiple sources.
     * Acceptă și „bare digits” în PS_SHOP_DETAILS (ex: "33913238").
     */
    private function getShopVatAndCif(): array
    {
        $vat = '';
        $cif = '';

        // 1) PS_SHOP_DETAILS (poate conține text/HTML)
        $details = (string) Configuration::get('PS_SHOP_DETAILS');
        if ($details !== '') {
            $text = strip_tags($details);
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = preg_replace('/\s+/', ' ', $text);

            if (preg_match('/\bRO\s*([0-9]{2,12})\b/i', $text, $m)) {
                $cif = $m[1];
            } elseif (preg_match('/\b(?:CIF|CUI|VAT|TVA)\s*[:#]?\s*(?:RO\s*)?([0-9]{2,12})\b/i', $text, $m)) {
                $cif = $m[1];
            } elseif (preg_match('/\b([0-9]{6,12})\b/', $text, $m)) {
                $cif = $m[1];
            }
            if ($cif !== '') { $vat = 'RO' . $cif; }
        }

        // 2) Shop address vat_number (dacă este setat)
        if ($vat === '' || $cif === '') {
            $shopAddressId = (int) Configuration::get('PS_SHOP_ADDRESS_ID');
            if ($shopAddressId) {
                $sa = new Address($shopAddressId);
                if (\Validate::isLoadedObject($sa) && !empty($sa->vat_number)) {
                    $maybe = trim((string)$sa->vat_number);
                    if (preg_match('/^RO\s*([0-9]{2,12})$/i', $maybe, $m)) {
                        $cif = $cif ?: $m[1];
                        $vat = 'RO' . $m[1];
                    } elseif (preg_match('/^([0-9]{2,12})$/', $maybe, $m)) {
                        $cif = $cif ?: $m[1];
                        $vat = 'RO' . $m[1];
                    }
                }
            }
        }

        // 3) Module fallbacks
        if ($vat === '') {
            $raw = (string) Configuration::get('TS_XMLINVOICE_SUPPLIER_CIF');
            if ($raw !== '') {
                if (preg_match('/^RO\s*([0-9]{2,12})$/i', $raw, $m)) {
                    $cif = $cif ?: $m[1];
                    $vat = 'RO' . $m[1];
                } else {
                    $digits = preg_replace('/\D+/', '', $raw);
                    if ($digits !== '') { $cif = $cif ?: $digits; $vat = 'RO' . $digits; }
                }
            }
        }
        if ($cif === '') {
            $raw = (string) Configuration::get('TS_XMLINVOICE_SUPPLIER_REGISTRATION');
            if ($raw !== '') {
                $digits = preg_replace('/\D+/', '', $raw);
                if ($digits !== '') { $cif = $digits; if ($vat === '') { $vat = 'RO' . $digits; } }
            }
        }

        // 4) Alte chei PS uzuale
        foreach (['PS_SHOP_TAX_ID', 'PS_SHOP_SIRET', 'PS_SHOP_RCS'] as $k) {
            if ($cif !== '' && $vat !== '') { break; }
            $val = (string) Configuration::get($k);
            $digits = preg_replace('/\D+/', '', $val);
            if ($digits !== '') {
                if ($cif === '') { $cif = $digits; }
                if ($vat === '') { $vat = 'RO' . $digits; }
            }
        }

        // Normalize
        $vat = strtoupper(trim((string)$vat));
        if ($vat !== '' && preg_match('/^[0-9]{2,12}$/', $vat)) { $vat = 'RO' . $vat; }
        if ($vat !== '' && preg_match('/^RO\s*$/i', $vat)) { $vat = ''; }
        if ($cif === '' && preg_match('/^RO\s*([0-9]{2,12})$/i', (string)$vat, $m2)) { $cif = $m2[1]; }
        if ($vat === '' && $cif !== '') { $vat = 'RO' . $cif; }

        return ['vat' => (string)$vat, 'registration' => (string)$cif];
    }
}
