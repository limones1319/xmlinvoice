<?php

require_once dirname(__DIR__) . '/autoload.php';

if (class_exists('PrestaShopBundle\\Controller\\Admin\\FrameworkBundleAdminController')
    && !class_exists('Modules\\Tsxmlinvoice\\Controller\\Admin\\XmlInvoiceController', false)
) {
    require_once dirname(__DIR__) . '/src/Controller/Admin/XmlInvoiceController.php';
}
