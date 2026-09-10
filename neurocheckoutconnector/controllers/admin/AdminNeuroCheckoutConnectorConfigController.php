<?php

class AdminNeuroCheckoutConnectorConfigController extends ModuleAdminController
{
    public $bootstrap = true;

    public function initContent()
    {
        parent::initContent();

        if (!$this->module || !Validate::isLoadedObject($this->module)) {
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminModules'));
            return;
        }

        $url = $this->context->link->getAdminLink(
            'AdminModules',
            true,
            [],
            ['configure' => $this->module->name]
        );

        Tools::redirectAdmin($url);
    }
}
