<?php

namespace Lollilop\Classes\Controller;

require_once _PS_ROOT_DIR_ . '/config/defines.inc.php';
require_once _PS_ROOT_DIR_ . '/app/AppKernel.php';

abstract class RestController extends \ModuleFrontController
{

    protected $kernel;

    public function __construct()
    {
        parent::__construct();

        $this->context = $this->prepareContext();
        $this->context->employee = new \Employee(1);
        $this->id_lang = (int)\Tools::getValue('id_lang', 1);
        $this->context->language = new \Language((int)$this->id_lang);
        
        if (!defined('_PS_ADMIN_DIR_')) {
            define('_PS_ADMIN_DIR_', '');
        }
 
        // We need to boot the global kernel to avoid errors when others
        // method tries to access to it.
        global $kernel;

        if (!$kernel) {
            $kernel = new \AppKernel(_PS_MODE_DEV_ ? 'dev' : 'prod', _PS_MODE_DEV_ ? true : false);

            $kernel->boot();
        }

        $this->kernel = $kernel;
    }

    public function init()
    {
        header('Content-Type:' . "application/json");

        parent::init();
    }

    public function run()
    {
        $request_method = $_SERVER['REQUEST_METHOD'];

        $this->dispatch($request_method);
    }

    private function prepareContext()
    {
        $context = \Context::getContext();

        // Change shop context ?
        if (\Shop::isFeatureActive() && \Tools::getValue('shop_context') !== false) {
            $context->cookie->shopContext = \Tools::getValue('shop_context');
        }

        $context->currency = new \Currency((int) \Configuration::get('PS_CURRENCY_DEFAULT'));

        $shop_id = '';
        \Shop::setContext(\Shop::CONTEXT_ALL);

        if ($context->cookie->shopContext) {
            $split = explode('-', $context->cookie->shopContext);

            if (count($split) == 2) {
                if ($split[0] == 'g') {
                    $shop_group_id = $split[1];
                    \Shop::setContext(\Shop::CONTEXT_GROUP, (int) $shop_group_id);
                } else {
                    $shop_id = $split[1];
                    \Shop::setContext(\Shop::CONTEXT_SHOP, (int) $shop_id);
                }
            }
        }

        if (!$shop_id) {
            $context->shop = new \Shop((int) \Configuration::get('PS_SHOP_DEFAULT'));
        } elseif ($context->shop->id != $shop_id) {
            $context->shop = new \Shop($shop_id);
        }

        return $context;
    }

    private function dispatch($method)
    {
        switch ($method) {
            case "GET":
                $this->processGet();
                break;
            case "POST":
                $this->processPost();
                break;
            case "PUT":
                $this->processPut();
                break;
            case "DELETE":
                $this->processDelete();
                break;
        }
    }

    // TODO: Move into helpers
    public function return404()
    {
        header("HTTP/1.0 404 Not Found");

        die();
    }

    public function return409()
    {
        header("HTTP/1.0 409 Conflict");

        die();
    }

    public function get($service)
    {
        return $this->kernel->getContainer()->get($service);
    }

    public function getJsonBody()
    {
        $request_body = file_get_contents('php://input');

        // Check if json is correct and return some error if it isn't.

        return json_decode($request_body, true) ?? [];
    }

    protected function processGet()
    {
    }
    protected function processPost()
    {
    }
    protected function processPut()
    {
    }
    protected function processDelete()
    {
    }
}
