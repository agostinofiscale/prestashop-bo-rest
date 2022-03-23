<?php

require dirname(__FILE__) . "/../../vendor/autoload.php";

use Lollilop\Classes\Controller\RestController;

// TODO: rename it in something else?
class LollilopDashboardModuleFrontController extends RestController
{
    public function processGet() {
        $module_name = Tools::getValue("module_name") ?? null;

        $id_shop = Tools::getValue("id_shop") ?? 1;

        // If specific name is given, it gets widget only from that module.
        $params = [
            'module_name' => $module_name
        ];

        $modules_widgets = Hook::exec(
            'widgetCards', 
            $params, 
            null,
            true,
            true,
            false,
            $id_shop
        );

        $widgets = $this->removeEmptyWidgets(
            $modules_widgets
        );

        die(
            json_encode(
                array_values($widgets),
                0,
                1
            )
        );
    }

    private function removeEmptyWidgets($widgets) {
        return array_filter(
            $widgets,
            function($widget, $key) {
                return !is_null($widget) && $widget !== '' && $widget !== "{}";
            },
            1
        );
    }
}