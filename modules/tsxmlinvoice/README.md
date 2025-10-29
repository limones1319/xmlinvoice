# TS XML Invoice

Generate CIUS-RO compliant UBL 2.1 XML invoices straight from the PrestaShop Back Office order view.

## Installation

1. Copy the `tsxmlinvoice` folder to your PrestaShop `modules/` directory (or install the module archive via the Back Office).
2. Navigate to **Modules > Module Manager** and enable **XML Invoice (CIUS-RO)**.
3. Click **Configure** and complete the supplier identification fields (CIF/VAT, trade register no., and legal address). Missing fields fall back to the global shop configuration.

## Usage

1. Open any order in the Back Office.
2. A new **View XML Invoice** button appears next to the standard invoice actions.
3. Clicking the button opens a new tab targeting the secure route `/tsxmlinvoice/generate/{id_order}`. The route validates the Admin token, generates the CIUS-RO XML on-the-fly, and streams it with the proper `application/xml` content type.

The generated document contains:

- Supplier details based on the module configuration or shop defaults.
- Customer/company data from the invoice address.
- Order totals, shipping, and line items converted to UBL 2.1 `<cac:InvoiceLine>` nodes with tax categories and unit codes.
- VAT subtotals including exemption codes when rates equal zero.

If the order cannot be found, the route returns HTTP 404. Invalid or missing tokens return HTTP 403.
