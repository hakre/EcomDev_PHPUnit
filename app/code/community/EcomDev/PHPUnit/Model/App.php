<?php
/**
 * PHP Unit test suite for Magento
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * @category   EcomDev
 * @package    EcomDev_PHPUnit
 * @copyright  Copyright (c) 2013 EcomDev BV (http://www.ecomdev.org)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 * @author     Ivan Chepurnyi <ivan.chepurnyi@ecomdev.org>
 */

/**
 * Application model for phpunit tests
 *
 */
class EcomDev_PHPUnit_Model_App extends Mage_Core_Model_App
{
    // Run types constants,
    // Don't know why
    // they are not defined in core application
    const RUN_TYPE_STORE = 'store';
    const RUN_TYPE_WEBSITE = 'website';
    const RUN_TYPE_GROUP = 'group';

    // Admin store code
    const ADMIN_STORE_CODE = 'admin';

    const AREA_ADMINHTML = EcomDev_PHPUnit_Model_App_Area::AREA_ADMINHTML;
    const AREA_ADMIN = EcomDev_PHPUnit_Model_App_Area::AREA_ADMIN;
    const AREA_FRONTEND = EcomDev_PHPUnit_Model_App_Area::AREA_FRONTEND;
    const AREA_GLOBAL = EcomDev_PHPUnit_Model_App_Area::AREA_GLOBAL;
    const AREA_TEST = EcomDev_PHPUnit_Model_App_Area::AREA_TEST;

    const AREA_PART_EVENTS = EcomDev_PHPUnit_Model_App_Area::PART_EVENTS;
    const AREA_PART_DESIGN = EcomDev_PHPUnit_Model_App_Area::PART_DESIGN;
    const AREA_PART_TRANSLATE = EcomDev_PHPUnit_Model_App_Area::PART_TRANSLATE;
    const AREA_PART_CONFIG = EcomDev_PHPUnit_Model_App_Area::PART_CONFIG;

    const INTERFACE_ISOLATION = 'EcomDev_PHPUnit_IsolationInterface';

    const REGISTRY_PATH_LAYOUT_SINGLETON = '_singleton/core/layout';
    const REGISTRY_PATH_DESIGN_PACKAGE_SINGLETON = '_singleton/core/design_package';

    const REGISTRY_PATH_SHARED_STORAGE = 'test_suite_shared_storage';

    const XML_PATH_LAYOUT_MODEL_FOR_TEST = 'phpunit/suite/layout/model';
    const XML_PATH_DESIGN_PACKAGE_MODEL_FOR_TEST = 'phpunit/suite/design/package/model';

    const XML_PATH_APP_AREA = 'phpunit/suite/app/area/class';
    const XML_PATH_CONTROLLER_FRONT = 'phpunit/suite/controller/front/class';
    const XML_PATH_CONTROLLER_REQUEST = 'phpunit/suite/controller/request/class';
    const XML_PATH_CONTROLLER_RESPONSE = 'phpunit/suite/controller/response/class';

    /**
     * Test scope data to be returned back after unit tests are finished
     *
     * @var array
     */
    protected static $_testScopeStack = array();

    /**
     * Configuration model class name for unit tests
     *
     * @var string
     */
    protected static $_configClass = 'EcomDev_PHPUnit_Model_Config';

    /**
     * Configuration model class name for unit tests
     *
     * @var string
     */
    protected static $_eventCollectionClass = 'Varien_Event_Collection';

    /**
     * List of areas that will be ignored in resetAreas() method
     *
     * @var array
     */
    protected $_resetIgnoreAreas = array(
        self::AREA_GLOBAL,
        self::AREA_TEST
    );

    /**
     * Enabled events flag
     *
     * @var boolean
     */
    protected $_eventsEnabled = true;

    /**
     * Dispatched events array
     *
     * @var array
     */
    protected $_dispatchedEvents = array();

    /**
     * List of module names stored by class name
     *
     * @var array
     * @deprecated since 0.3.0
     */
    protected $_moduleNameByClassName = array();

    /**
     * This method replaces application, event and config objects
     * in Mage to perform unit tests in separate Magento steam
     *
     */
    public static function applyTestScope()
    {
        // Save old environment variables
        self::$_testScopeStack[] = array(
            'app'      => EcomDev_Utils_Reflection::getRestrictedPropertyValue('Mage', '_app'),
            'config'   => EcomDev_Utils_Reflection::getRestrictedPropertyValue('Mage', '_config'),
            'events'   => EcomDev_Utils_Reflection::getRestrictedPropertyValue('Mage', '_events'),
            'registry' => EcomDev_Utils_Reflection::getRestrictedPropertyValue('Mage', '_registry'),
        );


        // Setting environment variables for unit tests
        EcomDev_Utils_Reflection::setRestrictedPropertyValue('Mage', '_config', new self::$_configClass);
        EcomDev_Utils_Reflection::setRestrictedPropertyValue('Mage', '_app', new self);
        EcomDev_Utils_Reflection::setRestrictedPropertyValue('Mage', '_events', new self::$_eventCollectionClass);
        EcomDev_Utils_Reflection::setRestrictedPropertyValue('Mage', '_registry', array());

        // All unit tests will be run in admin scope, to get rid of frontend restrictions
        Mage::app()->initTest();
    }

    /**
     * Initializes test scope for PHPUnit
     *
     * @return EcomDev_PHPUnit_Model_App
     */
    public function initTest()
    {
        if (version_compare(PHP_VERSION, '5.3.0', '>=')) {
            // If garbage collector is not enabled,
            // we enable it for tests
            if (!gc_enabled()) {
                gc_enable();
            }
        }

        Mage::setIsDeveloperMode(true);
        $this->_config = Mage::getConfig();
        $this->_initBaseConfig();
        $this->_initCache();

        // Set using cache
        // for things that shouldn't be reloaded each time
        $this->setCacheOptions(array(
            'eav' => 1,
            'layout' => 1,
            'translate' => 1
        ));

        // Clean cache before the whole suite is running
        $this->getCache()->clean();

        // Init modules runs install process for table structures,
        // It is required for setting up proper setup script
        $this->_initModules();

        $this->loadAreaPart(self::AREA_GLOBAL, self::AREA_PART_EVENTS);

        if ($this->_config->isLocalConfigLoaded()) {
            $this->_initCurrentStore(self::ADMIN_STORE_CODE, self::RUN_TYPE_STORE);
            $this->_initRequest();
            Mage_Core_Model_Resource_Setup::applyAllDataUpdates();
        }

        $layoutModel = $this->_getModelFromConfig(
            self::XML_PATH_LAYOUT_MODEL_FOR_TEST,
            self::INTERFACE_ISOLATION,
            'Layout model'
        );

        $this->replaceRegistry(self::REGISTRY_PATH_LAYOUT_SINGLETON,
                               $layoutModel);

        $designPackageModel = $this->_getModelFromConfig(
            self::XML_PATH_DESIGN_PACKAGE_MODEL_FOR_TEST,
            self::INTERFACE_ISOLATION,
            'Design package model'
        );

        $this->replaceRegistry(self::REGISTRY_PATH_DESIGN_PACKAGE_SINGLETON,
                               $designPackageModel);

        $this->loadAreaPart(self::AREA_TEST, self::AREA_PART_EVENTS);

        $this->replaceRegistry(self::REGISTRY_PATH_SHARED_STORAGE, new Varien_Object());
        return $this;
    }

    /**
     * Sets cache options for test case
     *
     * @param array $options
     * @return EcomDev_PHPUnit_Model_App
     */
    public function setCacheOptions(array $options)
    {
        EcomDev_Utils_Reflection::setRestrictedPropertyValue(
            $this->_cache,
            '_allowedCacheOptions',
            $options
        );
    }

    /**
     * Retrieve cache options for test case
     *
     * @return array
     */
    public function getCacheOptions()
    {
        return EcomDev_Utils_Reflection::getRestrictedPropertyValue(
            $this->_cache,
            '_allowedCacheOptions'
        );
    }

    /**
     * Retrieves a model from config and checks it on interface implementation
     *
     * @param string $configPath
     * @param string $interface
     * @param string $modelName
     * @throws RuntimeException
     * @return Mage_Core_Model_Abstract
     */
    protected function _getModelFromConfig($configPath, $interface, $modelName = 'Model')
    {
        $model = Mage::getModel(
            (string)$this->getConfig()->getNode($configPath)
        );

        if (!$model instanceof $interface) {
           throw new RuntimeException(
               sprintf('%s should implement %s to make possible running tests in isolation',
                       $modelName, $interface)
           );
        }

        return $model;
    }

    /**
     * Initialize front controller for test suite
     *
     * @return EcomDev_PHPUnit_Model_App
     */
    protected function _initFrontController()
    {
        $frontControllerClass = $this->_getClassNameFromConfig(self::XML_PATH_CONTROLLER_FRONT);
        $this->_frontController = new $frontControllerClass();
        Mage::register('controller', $this->_frontController);
        $this->_frontController->init();
        return $this;
    }

    /**
     * Retrieve application area
     *
     * @param   string $code
     * @return  EcomDev_PHPUnit_Model_App_Area
     */
    public function getArea($code)
    {
        if (!isset($this->_areas[$code])) {
            $appAreaClass = $this->_getClassNameFromConfig(
                self::XML_PATH_APP_AREA, self::INTERFACE_ISOLATION
            );
            $this->_areas[$code] = new $appAreaClass($code, $this);
        }
        return $this->_areas[$code];
    }

    /**
     * Resets areas parts, like events, translations, design
     *
     * @return EcomDev_PHPUnit_Model_App
     */
    public function resetAreas()
    {
        /* @var $area EcomDev_PHPUnit_Model_App_Area */
        foreach ($this->_areas as $code => $area) {
            if (!in_array($code, $this->_resetIgnoreAreas)) {
                $area->reset();
            }
        }
        return $this;
    }

    /**
     * Replace registry item value
     *
     * @param string $key
     * @param string $value
     * @return $this
     */
    public function replaceRegistry($key, $value)
    {
        $registry = EcomDev_Utils_Reflection::getRestrictedPropertyValue('Mage', '_registry');
        $registry[$key] = $value;
        EcomDev_Utils_Reflection::setRestrictedPropertyValue('Mage', '_registry', $registry);
        return $this;
    }

    /**
     * Removes event area
     *
     * @param string $code area code
     * @return EcomDev_PHPUnit_Model_App
     */
    public function removeEventArea($code)
    {
        if (isset($this->_events[$code])) {
            unset($this->_events[$code]);
        }

        return $this;
    }

    /**
     * Returns request for test suite
     *
     * @see Mage_Core_Model_App::getRequest()
     * @return EcomDev_PHPUnit_Controller_Request_Http
     */
    public function getRequest()
    {
        if ($this->_request === null) {
            $requestClass = $this->_getClassNameFromConfig(
                self::XML_PATH_CONTROLLER_REQUEST, self::INTERFACE_ISOLATION
            );
            $this->_request = new $requestClass;
        }

        return $this->_request;
    }

    /**
     * Returns response for test suite
     *
     * @see Mage_Core_Model_App::getResponse()
     * @return EcomDev_PHPUnit_Controller_Response_Http
     */
    public function getResponse()
    {
        if ($this->_response === null) {
            $responseClass = $this->_getClassNameFromConfig(
                self::XML_PATH_CONTROLLER_RESPONSE, self::INTERFACE_ISOLATION
            );
            $this->_response = new $responseClass;
        }

        return $this->_response;
    }

    /**
     * Returns class name from configuration path,
     * If $interface is specified, then it additionally checks it for implementation
     *
     *
     * @param string $configPath
     * @param string $interface
     * @throws RuntimeException
     * @return string
     */
    protected function _getClassNameFromConfig($configPath, $interface = null)
    {
        $className = (string)$this->getConfig()->getNode($configPath);

        $reflection = EcomDev_Utils_Reflection::getReflection($className);
        if ($interface !== null && !$reflection->implementsInterface($interface)) {
            throw new RuntimeException(
                sprintf('Invalid class name defined in configuration path %s, because %s does not implement %s interface',
                        $configPath, $interface)
            );
        }
        return $className;
    }

    /**
     * Overriden to fix issue with stores loading
     * (non-PHPdoc)
     * @see Mage_Core_Model_App::_initStores()
     */
    protected function _initStores()
    {
        $this->_store = null;
        parent::_initStores();
        return $this;
    }

    /**
     * Discard test scope for application, returns all the objects from live version
     *
     */
    public static function discardTestScope()
    {
        if(empty(self::$_testScopeStack)) {
            throw new RuntimeException('No test scope to discard');
        }

        $previousScope = array_pop(self::$_testScopeStack);

        // Setting environment variables for unit tests
        EcomDev_Utils_Reflection::setRestrictedPropertyValue('Mage', '_app', $previousScope['app']);
        EcomDev_Utils_Reflection::setRestrictedPropertyValue('Mage', '_config', $previousScope['config']);
        EcomDev_Utils_Reflection::setRestrictedPropertyValue('Mage', '_events', $previousScope['events']);
        EcomDev_Utils_Reflection::setRestrictedPropertyValue('Mage', '_registry', $previousScope['registry']);
    }

    /**
     * Returns module name for a particular object
     *
     * @param string|object $className
     * @throws RuntimeException if module name was not found for the passed class name
     * @return string
     * @deprecated since 0.3.0
     */
    public function getModuleNameByClassName($className)
    {
        return EcomDev_PHPUnit_Test_Case_Util::getModuleNameByClassName($className);
    }

    /**
     * Set associated module name for a class name,
     * Usually used for making possible dependency injection in the test cases
     *
     *
     * @param string $className
     * @param string $moduleName
     * @return EcomDev_PHPUnit_Model_App
     * @deprecated since 0.3.0
     */
    public function setModuleNameForClassName($className, $moduleName)
    {
        EcomDev_PHPUnit_Test_Case_Util::setModuleNameForClassName($className, $moduleName);
        return $this;
    }

    /**
     * Overriden for typehinting
     *
     * @return EcomDev_PHPUnit_Model_Config
     * (non-PHPdoc)
     * @see Mage_Core_Model_App::getConfig()
     */
    public function getConfig()
    {
        return parent::getConfig();
    }

    /**
     * Overriden for typehinting
     *
     * @return EcomDev_PHPUnit_Model_Layout
     * (non-PHPdoc)
     * @see Mage_Core_Model_App::getLayout()
     */
    public function getLayout()
    {
        return parent::getLayout();
    }

    /**
     * Disables events fire
     *
     * @return EcomDev_PHPUnit_Model_App
     */
    public function disableEvents()
    {
        $this->_eventsEnabled = false;
        return $this;
    }

    /**
     * Enable events fire
     *
     * @return EcomDev_PHPUnit_Model_App
     */
    public function enableEvents()
    {
        $this->_eventsEnabled = true;
        return $this;
    }

    /**
     * Overriden for disabling events
     * fire during fixture loading
     *
     * (non-PHPdoc)
     * @see Mage_Core_Model_App::dispatchEvent()
     */
    public function dispatchEvent($eventName, $args)
    {
        if ($this->_eventsEnabled) {
            parent::dispatchEvent($eventName, $args);
        }

        if (!isset($this->_dispatchedEvents[$eventName])) {
            $this->_dispatchedEvents[$eventName] = 0;
        }

        $this->_dispatchedEvents[$eventName]++;

        return $this;
    }


    /**
     * Returns number of times when the event was dispatched
     *
     * @param string $eventName
     * @return int
     */
    public function getDispatchedEventCount($eventName)
    {
        if (isset($this->_dispatchedEvents[$eventName])) {
            return $this->_dispatchedEvents[$eventName];
        }

        return 0;
    }


    /**
     * Resets dispatched events information
     *
     * @return EcomDev_PHPUnit_Model_App
     */
    public function resetDispatchedEvents()
    {
        $this->_dispatchedEvents = array();
        return $this;
    }
}
