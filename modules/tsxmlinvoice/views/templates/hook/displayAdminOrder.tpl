{if isset($tsxmlinvoice_url)}
    {assign var='tsxmlinvoice_button_id' value='tsxmlinvoice-button'}
    <a id="{$tsxmlinvoice_button_id}" class="btn btn-default" href="{$tsxmlinvoice_url|escape:'htmlall':'UTF-8'}" target="{$tsxmlinvoice_target|default:'_blank'|escape:'htmlall':'UTF-8'}" rel="noopener noreferrer">
        <i class="material-icons">code</i>
        {l s='View XML Invoice' mod='tsxmlinvoice'}
    </a>
    <script>
        (function () {
            function findInvoiceButton() {
                var selectors = [
                    'a.js-open-invoice-btn',
                    'a[data-role="view-invoice"]',
                    '#viewInvoice',
                    '#view_invoice',
                    'a[href*="generateInvoicePDF"]'
                ];

                for (var i = 0; i < selectors.length; i += 1) {
                    var candidate = document.querySelector(selectors[i]);
                    if (candidate) {
                        return candidate;
                    }
                }

                return null;
            }

            function moveXmlInvoiceButton() {
                var xmlInvoiceButton = document.getElementById('{$tsxmlinvoice_button_id|escape:'javascript'}');
                if (!xmlInvoiceButton || xmlInvoiceButton.dataset.tsxmlinvoiceMoved === '1') {
                    return;
                }

                var invoiceButton = findInvoiceButton();
                if (!invoiceButton || !invoiceButton.parentNode) {
                    return;
                }

                invoiceButton.parentNode.insertBefore(xmlInvoiceButton, invoiceButton.nextSibling);
                xmlInvoiceButton.dataset.tsxmlinvoiceMoved = '1';
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', moveXmlInvoiceButton);
            } else {
                moveXmlInvoiceButton();
            }
        })();
    </script>
{/if}
