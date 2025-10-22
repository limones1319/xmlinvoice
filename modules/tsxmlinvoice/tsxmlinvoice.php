<?php
/**
 * XML Invoice Module
 *
 * @author OpenAI
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class Tsxmlinvoice extends Module
{
    /** @var array */
    private $configFormValues = [
        'TS_XMLINVOICE_SUPPLIER_CIF' => '',
        'TS_XMLINVOICE_SUPPLIER_REGISTRATION' => '',
        'TS_XMLINVOICE_SUPPLIER_STREET' => '',
        'TS_XMLINVOICE_SUPPLIER_CITY' => '',
        'TS_XMLINVOICE_SUPPLIER_POSTCODE' => '',
        'TS_XMLINVOICE_SUPPLIER_COUNTRY' => '',
    ];

    public function __construct()
    {
        $this->name = 'tsxmlinvoice';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'OpenAI';
        $this->need_instance = 0;

        parent::__construct();

        $this->displayName = $this->l('XML Invoice (CIUS-RO)');
        $this->description = $this->l('Generate CIUS-RO UBL 2.1 XML invoices from the order page.');
        $this->ps_versions_compliancy = ['min' => '1.7.0.0', 'max' => _PS_VERSION_];
    }

    public function install()
    {
        return parent::install()
            && $this->registerHook('displayAdminOrder')
            && $this->installConfiguration();
    }

    public function uninstall()
    {
        return $this->removeConfiguration() && parent::uninstall();
    }

    private function installConfiguration()
    {
        foreach ($this->configFormValues as $key => $default) {
            Configuration::updateValue($key, $default);
        }

        return true;
    }

    private function removeConfiguration()
    {
        foreach (array_keys($this->configFormValues) as $key) {
            Configuration::deleteByName($key);
        }

        return true;
    }

    public function getContent()
    {
        $output = '';

        if (((bool) Tools::isSubmit('submitTsxmlinvoiceModule')) === true) {
            $this->postProcess();
            $output .= $this->displayConfirmation($this->l('Settings updated'));
        }

        return $output . $this->renderForm();
    }

    protected function renderForm()
    {
        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitTsxmlinvoiceModule';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name
            . '&tab_module=' . $this->tab
            . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = [
            'fields_value' => $this->getConfigFormValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        ];

        return $helper->generateForm([$this->getConfigForm()]);
    }

    protected function getConfigForm()
    {
        return [
            'form' => [
                'legend' => [
                    'title' => $this->l('Supplier details'),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                    [
                        'type' => 'text',
                        'name' => 'TS_XMLINVOICE_SUPPLIER_CIF',
                        'label' => $this->l('CIF/VAT Number'),
                    ],
                    [
                        'type' => 'text',
                        'name' => 'TS_XMLINVOICE_SUPPLIER_REGISTRATION',
                        'label' => $this->l('Trade Register Number'),
                    ],
                    [
                        'type' => 'text',
                        'name' => 'TS_XMLINVOICE_SUPPLIER_STREET',
                        'label' => $this->l('Street address'),
                    ],
                    [
                        'type' => 'text',
                        'name' => 'TS_XMLINVOICE_SUPPLIER_CITY',
                        'label' => $this->l('City'),
                    ],
                    [
                        'type' => 'text',
                        'name' => 'TS_XMLINVOICE_SUPPLIER_POSTCODE',
                        'label' => $this->l('Postcode'),
                    ],
                    [
                        'type' => 'text',
                        'name' => 'TS_XMLINVOICE_SUPPLIER_COUNTRY',
                        'label' => $this->l('Country code (ISO 3166-1 alpha-2)'),
                        'hint' => $this->l('Example: RO'),
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Save'),
                ],
            ],
        ];
    }

    protected function getConfigFormValues()
    {
        $values = [];

        foreach ($this->configFormValues as $key => $default) {
            $values[$key] = Tools::getValue($key, Configuration::get($key, $default));
        }

        return $values;
    }

    protected function postProcess()
    {
        foreach (array_keys($this->configFormValues) as $key) {
            Configuration::updateValue($key, Tools::getValue($key));
        }
    }

    public function hookDisplayAdminOrder(array $params)
    {
        if (empty($params['id_order'])) {
            return '';
        }

        /** @var \Symfony\Component\Routing\RouterInterface|null $router */
        $router = null;

        if (method_exists($this->context->controller, 'getContainer')) {
            $container = $this->context->controller->getContainer();
            if ($container && $container->has('router')) {
                $router = $container->get('router');
            }
        }

        if (null === $router && class_exists('\\PrestaShop\\PrestaShop\\Adapter\\SymfonyContainer')) {
            $container = \PrestaShop\PrestaShop\Adapter\SymfonyContainer::getInstance();
            if ($container && $container->has('router')) {
                $router = $container->get('router');
            }
        }

        if (null === $router) {
            return '';
        }
        $link = $router->generate(
            'modules_tsxmlinvoice_generate',
            [
                'id_order' => (int) $params['id_order'],
            ],
            \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL
        );

        $this->context->smarty->assign([
            'tsxmlinvoice_url' => $link,
            'tsxmlinvoice_target' => '_blank',
        ]);

        return $this->fetch('module:tsxmlinvoice/views/templates/hook/displayAdminOrder.tpl');
    }
}
