<?php

namespace Quantum;

use Quantum\DBO\Account;
use Quantum\pages\IPage;

class Core {

    /**
     * @var \Smarty
     */
    private $smarty;

    /**
     * @var array
     */
    private $settings;

    /**
     * @var DatabaseManager
     */
    private $internalDatabase;

    /**
     * @var array(DatabaseManager)
     */
    private $serverDatabase;

    /**
     * @var Translator
     */
    private $translator;

    /**
     * @var UserManager
     */
    private $userManager;

    /**
     * @var Core
     */
    private static $instance;

    /**
     * Core constructor.
     */
    public function __construct() {
        $this->initDefines();
        $this->initExceptionHandler();
        $this->initSmarty();
        $this->initConfiguration();
        $this->initDatabases();
        $this->initTranslator();
        $this->initUserManager();
        $this->initPlugins();

        Core::$instance = $this;
    }

    /**
     * Handle the request. Do actions and display the page.
     */
    public function execute() {
        // Only for development:
        $this->internalDatabase->createStructure();

        $this->smarty->debugging = $this->settings['in_dev'];

        $this->smarty->assign('system_pageTitle', 'Team Quantum');
        $this->smarty->assign('system_slogan', 'Quantum CMS <3');
        $this->smarty->assign('system_year', date('Y'));
        $this->smarty->assign('system_path', $this->settings['external_path']);
        $this->smarty->assign('system_currentUser', $this->getAccount());
        $this->smarty->assign('system_userManager', $this->getUserManager());
        $this->smarty->assign('system_date', date('d-m-Y'));
        $this->smarty->assign('system_time', date('H:i:s'));

        $path = explode('/', $this->prepareUri());
        $page = $this->settings['default_page'];

        if(count($path) > 0) {
            $page = $path[0];
        }

        $pageFullName = "\\App\\Pages\\" . $page;

        if(!class_exists($pageFullName)) {
            $this->throwNotFound();
        }
        /** @var $pageObject BasePage */
        $pageObject = new $pageFullName();

        if(!($pageObject instanceof BasePage)) {
            $this->throwNotFound();
        }

        $this->doAuthorization($pageObject);

        if ($pageObject instanceof ContainerPage) {
            $pageFullName = $pageFullName . '\\' . (isset($path[1]) ? $path[1] : '');
            if (! class_exists($pageFullName)) {
                $this->throwNotFound();
            }

            $pageObject->preRender($this, $this->smarty);

            array_shift($path);
            $pageObject = new $pageFullName;
            $this->doAuthorization($pageObject);
        }
        array_shift($path);

        $pageObject->setSmarty($this->smarty);
        $pageObject->setCore($this);
        $pageObject->setArgs($path);

        $this->renderPage($pageObject);
    }

    /**
     * Render page
     *
     * @param BasePage $page
     */
    protected function renderPage(BasePage $page)
    {
        $page->preRender($this, $this->smarty);
        $this->smarty->assign('pageTemplate', $page->getTemplate($this, $this->smarty));
        // Process sidebars
        PluginManager::processSidebars($this, $this->smarty);
        $this->smarty->display('index.tpl');
        $page->postRender($this, $this->smarty);
    }

    /**
     * Prepare uri for page routing
     *
     * @return string
     */
    protected function prepareUri()
    {
        $query = $this->getPathInfo();
        $query = str_replace($this->settings['base_path'], '', $query);
        return trim($query, '/');
    }

    /**
     * Do authorization for a page
     *
     * @param BasePage $page
     */
    protected function doAuthorization(BasePage $page)
    {
        if (method_exists($page, 'authorize')) {
            if ( ! $page->authorize($this)) {
                $this->redirect('/');
                exit;
            }
        }
    }

    /**
     * Throw page not found
     */
    public function throwNotFound()
    {
        $this->smarty->assign('pageTemplate', '404.tpl');
        $this->smarty->display('index.tpl');
        exit;
    }

    /**
     * Run all cron jobs which are needed
     */
    public function executeCronJobs() {
        PluginManager::processCronJobs($this);
    }

    /**
     * @return Translator
     */
    public function getTranslator() {
        return $this->translator;
    }

    public static function getInstance() {
        return Core::$instance;
    }

    private function initDefines() {
        if(!defined('DS')) {
            define('DS', DIRECTORY_SEPARATOR);
        }

        if(!defined('SYSTEM_DIR')) {
            define('SYSTEM_DIR', dirname(__FILE__) . DS);
        }

        if(!defined('ROOT_DIR')) {
            define('ROOT_DIR', realpath(SYSTEM_DIR . '..') . DS);
        }

        if (!defined('APP_DIR')) {
            define('APP_DIR', ROOT_DIR . DS . 'app' . DS);
        }

        if (!defined('STORAGE_DIR')) {
            define('STORAGE_DIR', ROOT_DIR . DS . 'storage' . DS);
        }
    }

    /**
     * Initialise the template system
     */
    private function initSmarty() {
        $this->smarty = new \Smarty();
        $this->smarty->setTemplateDir(APP_DIR.'templates');
        $this->smarty->setCompileDir(STORAGE_DIR.'templates');

        $pluginDirectories = $this->smarty->getPluginsDir();
        $pluginDirectories[] = SYSTEM_DIR . 'smarty';
        $this->smarty->setPluginsDir($pluginDirectories);
    }

    /**
     * Loads the configuration file
     */
    private function initConfiguration() {
        $this->settings = require ROOT_DIR . 'config.php';
        $this->settings['external_path'] = $this->detectBaseUrl();
    }

    private function initDatabases() {
        $this->internalDatabase = new DatabaseManager($this->settings['internal_database'],
            ROOT_DIR . 'mappings' . DS . 'internal' . DS);

        $this->serverDatabase = array();
        $this->serverDatabase['account'] = new DatabaseManager($this->settings['server_database']['account'],
            ROOT_DIR . 'mappings' . DS . 'account' . DS);
        $this->serverDatabase['player'] = new DatabaseManager($this->settings['server_database']['player'],
            ROOT_DIR . 'mappings' . DS . 'player' . DS);
    }

    private function initExceptionHandler() {
        new ExceptionHandler();
    }

    private function initTranslator() {
        $this->translator = new Translator('DE', $this->internalDatabase);
    }

    /**
     * Returns the database manager for the database given
     * @param $type string Database type (player, account, log)
     * @return DatabaseManager
     */
    public function getServerDatabase($type) {
        return $this->serverDatabase[$type];
    }

    /**
     * @return DatabaseManager
     */
    public function getInternalDatabase() {
        return $this->internalDatabase;
    }

    /**
     * Generates html code which display recaptcha
     * @return string
     */
    public function getRecaptchaHtml() {
        if($this->settings['in_dev'])
            return '';

        return '<script src="https://www.google.com/recaptcha/api.js"></script>' .
                '<div class="g-recaptcha" data-sitekey="' . $this->settings['recaptcha']['public'] . '"></div>';
    }

    /**
     * Check if the captcha was solved
     * @return boolean
     */
    public function validateCaptcha() {
        if($this->settings['in_dev']) {
            return true;
        }

        $recaptchURL = 'https://www.google.com/recaptcha/api/siteverify';
        $secret = $this->settings['recaptcha']['private'];
        $data = array(
            'secret' => $secret,
            'response' => $_POST['g-recaptcha-response'],
            'remoteip' => $_SERVER['REMOTE_ADDR']
        );
        $request = array(
            'http' => array(
                'header' => 'Content-type: application/x-www-form-urlencoded\r\n',
                'method' => 'POST',
                'content' => http_build_query($data)
            )
        );
        $context = stream_context_create($request);
        $result = file_get_contents($recaptchURL, false, $context);
        $json = json_decode($result);
        return $json->{'success'} == 1;
    }

    public function redirect($page) {
        header('Location: ' . $this->settings['external_path'] . $page);
    }

    public function addError($message, array $format = array()) {
        $errors = $this->smarty->getTemplateVars('errors');
        if($errors === null) {
            $errors = array();
        }
        $message = $this->translator->translate($message);
        foreach($format as $key => $value) {
            $message = str_replace('%'.$key.'%', $value, $message);
        }

        $errors[] = $message;
        $this->smarty->assign('errors', $errors);
    }

    /**
     * Create the hash value from the clean text (use this function because this can be overwritten by plugins)
     * @param $clean
     * @param $source mixed On Login its an \Quantum\DBO\Account use this source if the hash contains a seed
     * @return string hash value
     */
    public function createHash($clean, $source) {
        // todo implement plugin possibility to override this

        // Default MySQL5 Hash implementation
        return '*' . strtoupper(sha1(sha1($clean, true)));
    }

    /**
     * Sets the current login into the session
     * @param $account Account
     */
    public function setAccount($account) {
        $this->userManager->setAccount($account);
    }

    /**
     * Gets the current logged in user
     * @return null|Account
     */
    public function getAccount() {
        return $this->userManager->getCurrentAccount();
    }

    /**
     * Get path info from path_info env variable or from url
     *
     * @return string
     */
    public function getPathInfo()
    {
        static $pathInfo;

        if ($pathInfo == null) {
            if (isset($_SERVER['PATH_INFO'])) {
                $pathInfo = $_SERVER['PATH_INFO'];
            } else {
                $query = isset($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : '';
                $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
                $pathInfo = rtrim(str_replace('?'.$query, '', $uri), '/');
            }
        }
        return $pathInfo;
    }

    private function initPlugins() {
        PluginManager::load($this);
    }

    private function initUserManager() {
        $this->userManager = new UserManager($this);
    }

    public function getUserManager() {
        return $this->userManager;
    }

    /**
     * Expose the settings to the other classes
     *
     * @return array
     */
    public function getSettings()
    {
        return $this->settings;
    }

    /**
     * @return bool
     */
    public function inDev() {
        return $this->settings['in_dev'] == true;
    }

    /**
     * Autodetect base url
     *
     * @return mixed
     */
    protected function detectBaseUrl()
    {
        $base_url = isset($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off' ? 'https' : 'http';
        $base_url .= '://'. $_SERVER['HTTP_HOST'];
        $base_url .= str_replace(basename($_SERVER['SCRIPT_NAME']), '', $_SERVER['SCRIPT_NAME']);

        return $base_url;
    }
}