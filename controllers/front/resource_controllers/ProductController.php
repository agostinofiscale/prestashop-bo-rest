<?php

use Lollilop\Classes\Controller\RestController;
use Lollilop\Classes\Controller\AbstractControllerWrapper;
use Limenius\Liform\Resolver;
use Limenius\Liform\Liform;
use Limenius\Liform\Liform\Transformer;
use PrestaShopBundle\Model\Product\AdminModelAdapter;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use PrestaShopBundle\Form\Admin\Product\ProductCategories;
use PrestaShopBundle\Form\Admin\Product\ProductCombination;
use PrestaShopBundle\Form\Admin\Product\ProductCombinationBulk;
use PrestaShopBundle\Form\Admin\Product\ProductInformation;
use PrestaShopBundle\Form\Admin\Product\ProductOptions;
use PrestaShopBundle\Form\Admin\Product\ProductPrice;
use PrestaShopBundle\Form\Admin\Product\ProductQuantity;
use PrestaShopBundle\Form\Admin\Product\ProductSeo;
use PrestaShopBundle\Form\Admin\Product\ProductShipping;
use Symfony\Component\HttpFoundation\Request;
use PrestaShopBundle\Service\Hook\HookFinder;
use Symfony\Component\HttpFoundation\JsonResponse;

class ProductController extends RestController {

    /**
     * Get a product
     */
    public function processGet() {
        $id_resource = $_GET["id_resource"] ?? null;

        $response = null;

        if ($id_resource) {
            $productObj = new Product(
                                $id_resource,
                                true);

            if ($productObj->id == null) 
                $this->return404();
            
            $modelMapper = $this->get('prestashop.adapter.admin.model.product');

            $product = $modelMapper->getFormData($productObj);
            $combinations = $modelMapper->getAttributesResume($productObj);

            $attributeProvider = $this->get("prestashop.adapter.data_provider.attribute");
            
            if ($combinations) {
                foreach ($combinations as &$combination) {
                    $combination["images"] =  array_column(
                        array_unique(
                            $attributeProvider->getImages($combination["id_product_attribute"]),
                            SORT_REGULAR
                        ),
                        "id"
                    );
                }
            }

            $response = $this->adaptProductToGet($product);

            $response["combinations"] = $combinations;
        } else {
            if (!isset ($_GET['page']) ) {  
                $page = 1;  
            } else {  
                $page = $_GET['page'];  
            }

            //define total number of results you want per page  
            $results_per_page = 10;  

            $start = ($page-1) * $results_per_page;  
            $limit = $results_per_page;
            $order_by = 'id_product';
            $order_way = 'DESC';
            
            $productProvider = $this->get('prestashop.core.admin.data_provider.product_interface');

            $products = $productProvider->getCatalogProductList(
                $start,
                $limit,
                $order_by,
                $order_way,
                $_GET,
                true // avoid persistences
            );

            $response = $products;
        }

        $response_json = json_encode($response);

        $this->ajaxDie($response_json);
    }

    /**
     * Create a new product
     * 
     * TODO: Improve error handling
     * TODO: Improve temporany product creation and response...
     * Look: https://github.com/PrestaShop/PrestaShop/blob/develop/src/PrestaShopBundle/Controller/Admin/ProductController.php#L458
     */
    public function processPost() {
        $jsonPostData = $this->getJsonBody();

        if (!array_key_exists("id_product", $jsonPostData)) {
            $product = $this->createTemporanyProduct();


            $product_for_view = new Product($product->id, true);
            $product_for_view->images_url = [];

            $response_json = json_encode($product_for_view);
            
            $this->ajaxDie($response_json);
        }

        // Check if current shop is all context
        $productAdapter = $this->get('prestashop.adapter.data_provider.product');

        $product = $productAdapter->getProduct($jsonPostData["id_product"]);

        $shopContext = $this->get('prestashop.adapter.shop.context');
        $legacyContextService = $this->get('prestashop.adapter.legacy.context');
        $isMultiShopContext = count($shopContext->getContextListShopID()) > 1;

        $modelMapper = $this->get('prestashop.adapter.admin.model.product');
        $adminProductWrapper = $this->get('prestashop.adapter.admin.wrapper.product');

        $form = $this->createProductForm($product, 
                                        $modelMapper);

        $formBulkCombinations = $this->get('form.factory')->create(
            ProductCombinationBulk::class,
            null,
            [
                'iso_code' => $this->context->currency->iso_code,
            ]
        ); 

        // Legacy code. To fix when Object model will change. But report Hooks.
        $request = Request::createFromGlobals();
        $postData = $request->request->all();
        $combinationsList = [];
        if (!empty($postData)) {
            foreach ($postData as $postKey => $postValue) {
                if (preg_match('/^combination_.*/', $postKey)) {
                    $combinationsList[$postKey] = $postValue;
                    $postData['form'][$postKey] = $postValue; // need to validate the form
                }
            }

            // Duplicate Request to be a valid form (like it was real) with postData modified ..
            $request = $request->duplicate(
                $request->query->all(),
                $postData,
                $request->attributes->all(),
                $request->cookies->all(),
                $request->files->all(),
                $request->server->all()
            );
        }

        $form->submit(json_decode($request->getContent(), true));
        $formData = $form->getData();
        $formData['step3']['combinations'] = $combinationsList;

        try {
            if ($form->isSubmitted()) {
                if ($request->isXmlHttpRequest()) {
                    $errorMessage = $this->getDemoModeErrorMessage();

                    return $this->returnErrorJsonResponse(
                        ['error' => [$errorMessage]],
                        Response::HTTP_SERVICE_UNAVAILABLE
                    );
                }
                if ($form->isValid()) {
                    //define POST values for keeping legacy adminController skills
                    $_POST = $modelMapper->getModelData($formData, $isMultiShopContext) + $_POST;
                    $_POST['form'] = $formData;
                    $_POST['state'] = Product::STATE_SAVED;

                    $adminProductController = $adminProductWrapper->getInstance();
                    $adminProductController->setIdObject($formData['id_product']);
                    $adminProductController->setAction('save');

                    // Hooks: this will trigger legacy AdminProductController, postProcess():
                    // actionAdminSaveBefore; actionAdminProductsControllerSaveBefore
                    // actionProductAdd or actionProductUpdate (from processSave() -> processAdd() or processUpdate())
                    // actionAdminSaveAfter; actionAdminProductsControllerSaveAfter
                    $productSaveResult = $adminProductController->postCoreProcess();

                    if (false == $productSaveResult) {
                        return $this->returnErrorJsonResponse(
                            ['error' => $adminProductController->errors],
                            Response::HTTP_BAD_REQUEST
                        );
                    }

                    $product = $productSaveResult;

                    /* @var Product $product */
                    $adminProductController->processSuppliers($product->id);
                    $adminProductController->processFeatures($product->id);
                    $adminProductController->processSpecificPricePriorities();
                    foreach ($_POST['combinations'] as $combinationValues) {
                        $adminProductWrapper->processProductAttribute($product, $combinationValues);
                        // For now, each attribute set the same value.
                        $adminProductWrapper->processDependsOnStock(
                            $product,
                            ($_POST['depends_on_stock'] == '1'),
                            $combinationValues['id_product_attribute']
                        );
                    }
                    $adminProductWrapper->processDependsOnStock($product, ($_POST['depends_on_stock'] == '1'));

                    // If there is no combination, then quantity and location are managed for the whole product (as combination ID 0)
                    // In all cases, legacy hooks are triggered: actionProductUpdate and actionUpdateQuantity
                    if (count($_POST['combinations']) === 0 && isset($_POST['qty_0'])) {
                        $adminProductWrapper->processQuantityUpdate($product, $_POST['qty_0']);
                        $adminProductWrapper->processLocation($product, (string) $_POST['location']);
                    }
                    // else quantities are managed from $adminProductWrapper->processProductAttribute() above.

                    $adminProductWrapper->processProductOutOfStock($product, $_POST['out_of_stock']);

                    $customizationFieldsIds = $adminProductWrapper
                        ->processProductCustomization($product, $_POST['custom_fields']);

                    $adminProductWrapper->processAttachments($product, $_POST['attachments']);

                    $adminProductController->processWarehouses();

                    $response = new JsonResponse();
                    $response->setData([
                        'product' => $product,
                        'customization_fields_ids' => $customizationFieldsIds,
                    ]);

                    if ($request->isXmlHttpRequest()) {
                        return $response;
                    }
                } elseif ($request->isXmlHttpRequest()) {
                    return $this->returnErrorJsonResponse(
                        $this->getFormErrorsForJS($form),
                        Response::HTTP_BAD_REQUEST
                    );
                } else {
                    $errors = array();
                    foreach ($form as $fieldName => $formField) {
                        foreach ($formField->getErrors(true) as $error) {
                            $errors[$fieldName] = $error->getMessage();
                        }
                    }
                    
                    var_dump($errors); die();
                }
            }
        } catch (Exception $e) {
            // this controller can be called as an AJAX JSON route or a HTML page
            // so we need to return the right type of response if an exception it thrown
            if ($request->isXmlHttpRequest()) {
                return $this->returnErrorJsonResponse(
                    [],
                    Response::HTTP_INTERNAL_SERVER_ERROR
                );
            }

            throw $e;
        }

        $response_json = json_encode($product);

        $this->ajaxDie($response_json);
    }

    private function createTemporanyProduct() {
        $productProvider = $this->get('prestashop.core.admin.data_provider.product_interface');
        $languages = $this->get('prestashop.adapter.legacy.context')->getLanguages();

        /** @var ProductInterfaceProvider $productProvider */
        $productAdapter = $this->get('prestashop.adapter.data_provider.product');
        $productShopCategory = $this->context->shop->id_category;

        /** @var Product $product */
        $product = $productAdapter->getProductInstance();
        $product->id_category_default = $productShopCategory;

        /** @var TaxRuleDataProvider $taxRuleDataProvider */
        $taxRuleDataProvider = $this->get('prestashop.adapter.data_provider.tax');
        $product->id_tax_rules_group = $taxRuleDataProvider->getIdTaxRulesGroupMostUsed();
        $product->active = $productProvider->isNewProductDefaultActivated();
        $product->state = Product::STATE_TEMP;

        //set name and link_rewrite in each lang
        foreach ($languages as $lang) {
            $product->name[$lang['id_lang']] = '';
            $product->link_rewrite[$lang['id_lang']] = '';
        }

        $product->save();
        $product->addToCategories([$productShopCategory]);

        return $product;

    }

    private function createProductForm(Product $product, AdminModelAdapter $modelMapper)
    {
        $formBuilder = $this->get('form.factory')->createBuilder(
            FormType::class,
            $modelMapper->getFormData($product),
            [
                'csrf_protection' => false,
                'allow_extra_fields' => true
            ]
        )
            ->add('id_product', HiddenType::class)
            ->add('step1', ProductInformation::class)
            ->add('step2', ProductPrice::class, ['id_product' => $product->id])
            ->add('step3', ProductQuantity::class)
            ->add('step4', ProductShipping::class)
            ->add('step5', ProductSeo::class, [
                'mapping_type' => $product->getRedirectType(),
            ])
            ->add('step6', ProductOptions::class);

        // Prepare combination form (fake but just to validate the form)
        $combinations = $product->getAttributesResume(
            $this->context->language->id
        );

        if (is_array($combinations)) {
            $maxInputVars = (int) ini_get('max_input_vars');
            $combinationsCount = count($combinations) * 25;
            $combinationsInputs = ceil($combinationsCount / 1000) * 1000;

            if ($combinationsInputs > $maxInputVars) {
                $this->addFlash(
                    'error',
                    $this->trans(
                        'The value of the PHP.ini setting "max_input_vars" must be increased to %value% in order to be able to submit the product form.',
                        'Admin.Notifications.Error',
                        ['%value%' => $combinationsInputs]
                    )
                );
            }

            foreach ($combinations as $combination) {
                $formBuilder->add(
                    'combination_' . $combination['id_product_attribute'],
                    ProductCombination::class,
                    ['allow_extra_fields' => true]
                );
            }
        }

        return $formBuilder->getForm();
    }

    private function adaptProductToGet($product) {
        $result = [];

        // Destructuring steps
        foreach ($product as $key => $property) {
            if (is_array($property)) {
                $result = array_merge($result, $property);
            } else {
                $result = array_merge($result, [$key => $property]);
            }
        }

        // Get direct link for images
        foreach($result["images"] as &$image) {
            $image["direct_link"] = $this->context->link->getImageLink($result["link_rewrite"][1], $image["id"], ImageType::getFormatedName('home'));
        }

        return $result;
    }
}