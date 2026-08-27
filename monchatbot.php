<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class MonChatbot extends Module
{
    public function __construct()
    {
        $this->name = 'monchatbot';
        $this->tab = 'front_office_features';
        $this->version = '1.0.0';
        $this->author = 'Hanan Esskoury & Ibtissam Tiheroui';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '1.7', 'max' => '9.99.99'];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Chatbot IA');
        $this->description = $this->l('Un chatbot multilingue qui aide vos clients à trouver facilement les produits de votre boutique');
    }

    public function install()
    {
        return parent::install()
            && $this->registerHook('displayFooter')
            && $this->registerHook('actionFrontControllerSetMedia')
            && Configuration::updateValue('MONCHATBOT_GEMINI_API_KEY', '')
            && Configuration::updateValue('MONCHATBOT_ENABLED', true)
            && Configuration::updateValue('MONCHATBOT_NAME', 'Assistant')
            && Configuration::updateValue('MONCHATBOT_WELCOME_MSG', 'Bonjour 👋 Je suis votre assistant.')
            && Configuration::updateValue('MONCHATBOT_MAX_PRODUCTS', 200);
    }

    public function uninstall()
    {
        Configuration::deleteByName('MONCHATBOT_GEMINI_API_KEY');
        Configuration::deleteByName('MONCHATBOT_ENABLED');
        Configuration::deleteByName('MONCHATBOT_NAME');
        Configuration::deleteByName('MONCHATBOT_WELCOME_MSG');
        Configuration::deleteByName('MONCHATBOT_MAX_PRODUCTS');
        return parent::uninstall();
    }

    public function hookActionFrontControllerSetMedia($params)
    {
        $this->context->controller->registerStylesheet(
            'monchatbot-css',
            'modules/' . $this->name . '/views/css/chatbot.css'
        );

        $this->context->controller->registerJavascript(
            'monchatbot-js',
            'modules/' . $this->name . '/views/js/chatbot.js'
        );
    }

    public function hookDisplayFooter($params)
    {
        return $this->display(__FILE__, 'chatbox.tpl');
    }

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitMonChatbot')) {
            Configuration::updateValue('MONCHATBOT_ENABLED', (bool)Tools::getValue('MONCHATBOT_ENABLED'));
            Configuration::updateValue('MONCHATBOT_NAME', Tools::getValue('MONCHATBOT_NAME'));
            Configuration::updateValue('MONCHATBOT_WELCOME_MSG', Tools::getValue('MONCHATBOT_WELCOME_MSG'));
            Configuration::updateValue('MONCHATBOT_MAX_PRODUCTS', (int)Tools::getValue('MONCHATBOT_MAX_PRODUCTS'));

            $newApiKey = Tools::getValue('MONCHATBOT_GEMINI_API_KEY');
            if (!empty($newApiKey)) {
                Configuration::updateValue('MONCHATBOT_GEMINI_API_KEY', $newApiKey);
            }

            $output .= $this->displayConfirmation($this->l('Paramètres enregistrés avec succès.'));
        }

        return $output . $this->renderForm();
    }

    protected function renderForm()
    {
        $hasApiKey = (bool)Configuration::get('MONCHATBOT_GEMINI_API_KEY');

        $fields_form = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Mon Chatbot IA'),
                ],
                'input' => [
                    [
                        'type' => 'switch',
                        'label' => $this->l('Activer le chatbot'),
                        'name' => 'MONCHATBOT_ENABLED',
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'active_on', 'value' => 1, 'label' => $this->l('Oui')],
                            ['id' => 'active_off', 'value' => 0, 'label' => $this->l('Non')],
                        ],
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Nom du chatbot'),
                        'name' => 'MONCHATBOT_NAME',
                    ],
                    [
                        'type' => 'textarea',
                        'label' => $this->l('Message de bienvenue'),
                        'name' => 'MONCHATBOT_WELCOME_MSG',
                    ],
                    [
                        'type' => 'password',
                        'label' => $this->l('Clé API Gemini'),
                        'name' => 'MONCHATBOT_GEMINI_API_KEY',
                        'desc' => $hasApiKey
                            ? $this->l('Laisser vide pour conserver la clé actuelle. ✅ Une clé est actuellement enregistrée.')
                            : $this->l('Aucune clé enregistrée pour le moment.'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Nombre max de produits envoyés en contexte'),
                        'name' => 'MONCHATBOT_MAX_PRODUCTS',
                        'class' => 'fixed-width-sm',
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Enregistrer'),
                ],
            ],
        ];

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->submit_action = 'submitMonChatbot';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->fields_value['MONCHATBOT_ENABLED'] = Configuration::get('MONCHATBOT_ENABLED');
        $helper->fields_value['MONCHATBOT_NAME'] = Configuration::get('MONCHATBOT_NAME');
        $helper->fields_value['MONCHATBOT_WELCOME_MSG'] = Configuration::get('MONCHATBOT_WELCOME_MSG');
        $helper->fields_value['MONCHATBOT_GEMINI_API_KEY'] = '';
        $helper->fields_value['MONCHATBOT_MAX_PRODUCTS'] = Configuration::get('MONCHATBOT_MAX_PRODUCTS');

        return $helper->generateForm([$fields_form]);
    }
}