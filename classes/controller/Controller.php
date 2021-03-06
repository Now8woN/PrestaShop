<?php
/*
* 2007-2014 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Open Software License (OSL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/osl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author PrestaShop SA <contact@prestashop.com>
*  @copyright  2007-2014 PrestaShop SA
*  @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

/**
 * @since 1.5.0
 */
abstract class ControllerCore
{
	/**
	 * @var Context
	 */
	protected $context;

	/**
	 * @var array list of css files
	 */
	public $css_files = array();

	/**
	 * @var array list of javascript files
	 */
	public $js_files = array();

	/**
	 * @var array list of php error
	 */
	public static $php_errors = array();

	/**
	 * @var bool check if header will be displayed
	 */
	protected $display_header;

	/**
	 * @var string template name for page content
	 */
	protected $template;

	/**
	 * @var string check if footer will be displayed
	 */
	protected $display_footer;

	/**
	 * @var string check if only content will be displayed
	 */
	protected $content_only = false;

	/**
	 * @var bool If ajax parameter is detected in request, set this flag to true
	 */
	public $ajax = false;
	protected $json = false;
	protected $status = '';

	protected $redirect_after = null;
	
	public $controller_type;
	public $php_self;	
	/**
	 * check that the controller is available for the current user/visitor
	 */
	abstract public function checkAccess();

	/**
	 * check that the current user/visitor has valid view permissions
	 */
	abstract public function viewAccess();

	/**
	 * Initialize the page
	 */
	public function init()
	{
		$old_error_handler = set_error_handler(array(__CLASS__, 'myErrorHandler'));
		if (!defined('_PS_BASE_URL_'))
			define('_PS_BASE_URL_', Tools::getShopDomain(true));
		if (!defined('_PS_BASE_URL_SSL_'))
			define('_PS_BASE_URL_SSL_', Tools::getShopDomainSsl(true));
	}

	/**
	 * Do the page treatment : post process, ajax process, etc.
	 */
	abstract public function postProcess();

	/**
	 * Display page view
	 */
	abstract public function display();

	/**
	 * Redirect after process if no error
	 */
	abstract protected function redirect();

	/**
	 * Set default media list for controller
	 */
	abstract public function setMedia();

	/**
	 * Get an instance of a controller
	 *
	 * @param string $class_name
	 * @param bool $auth
	 * @param bool $ssl
	 */
	public static function getController($class_name, $auth = false, $ssl = false)
	{
		return new $class_name($auth, $ssl);
	}

	public function __construct()
	{
		if (is_null($this->display_header))
			$this->display_header = true;

		if (is_null($this->display_footer))
			$this->display_footer = true;

		$this->context = Context::getContext();
		$this->context->controller = $this;
		// Usage of ajax parameter is deprecated
		$this->ajax = Tools::getValue('ajax') || Tools::isSubmit('ajax');
	}

	/**
	 * Start controller process (this method shouldn't be overriden !)
	 */
	public function run()
	{
		$this->init();
		if ($this->checkAccess())
		{
			// setMedia MUST be called before postProcess
			if (!$this->content_only && ($this->display_header || (isset($this->className) && $this->className)))
				$this->setMedia();

			// postProcess handles ajaxProcess
			$this->postProcess();

			if (!empty($this->redirect_after))
				$this->redirect();

			if (!$this->content_only && ($this->display_header || (isset($this->className) && $this->className)))
				$this->initHeader();

			if ($this->viewAccess())
				$this->initContent();
			else
				$this->errors[] = Tools::displayError('Access denied.');

			if (!$this->content_only && ($this->display_footer || (isset($this->className) && $this->className)))
				$this->initFooter();

			// default behavior for ajax process is to use $_POST[action] or $_GET[action]
			// then using displayAjax[action]
			if ($this->ajax)
			{
				$action = Tools::toCamelCase(Tools::getValue('action'), true);
				if (!empty($action) && method_exists($this, 'displayAjax'.$action)) 
					$this->{'displayAjax'.$action}();
				elseif (method_exists($this, 'displayAjax'))
					$this->displayAjax();
			}
			else
				$this->display();
		}
		else
		{
			$this->initCursedPage();
			$this->smartyOutputContent($this->layout);
		}
	}

	public function displayHeader($display = true)
	{
		$this->display_header = $display;
	}

	public function displayFooter($display = true)
	{
		$this->display_footer = $display;
	}

	public function setTemplate($template)
	{
		$this->template = $template;
	}

	/**
	 * Assign smarty variables for the page header
	 */
	abstract public function initHeader();

	/**
	 * Assign smarty variables for the page main content
	 */
	abstract public function initContent();

	/**
	 * Assign smarty variables when access is forbidden
	 */
	abstract public function initCursedPage();

	/**
	 * Assign smarty variables for the page footer
	 */
	abstract public function initFooter();

	/**
	 * Add a new stylesheet in page header.
	 *
	 * @param mixed $css_uri Path to css file, or list of css files like this : array(array(uri => media_type), ...)
	 * @param string $css_media_type
	 * @return true
	 */
	public function addCSS($css_uri, $css_media_type = 'all', $offset = null)
	{
		if (!is_array($css_uri))
			$css_uri = array($css_uri);

		foreach ($css_uri as $css_file => $media)
		{
			if (is_string($css_file) && strlen($css_file) > 1)
				$css_path = Media::getCSSPath($css_file, $media);
			else
				$css_path = Media::getCSSPath($media, $css_media_type);

			if ($css_path && !in_array($css_path, $this->css_files))
			{
				$size = count($this->css_files);
				if ($offset === null || $offset > $size || $offset < 0 || !is_numeric($offset))
					$offset = $size;

				$this->css_files = array_merge(array_slice($this->css_files, 0, $offset), $css_path, array_slice($this->css_files, $offset));
			}
		}
	}

	/**
	 * Add a new javascript file in page header.
	 *
	 * @param mixed $js_uri
	 * @return void
	 */
	public function addJS($js_uri)
	{
		if (is_array($js_uri))
			foreach ($js_uri as $js_file)
			{
				$js_path = Media::getJSPath($js_file);
				if ($js_path && !in_array($js_path, $this->js_files))
					$this->js_files[] = $js_path;
			}
		else
		{
			$js_path = Media::getJSPath($js_uri);
			if ($js_path)
				$this->js_files[] = $js_path;
		}
	}

	/**
	 * Add a new javascript file in page header.
	 *
	 * @param mixed $js_uri
	 * @return void
	 */
	public function addJquery($version = null, $folder = null, $minifier = true)
	{
		$this->addJS(Media::getJqueryPath($version, $folder, $minifier));
	}

	/**
	 * Add a new javascript file in page header.
	 *
	 * @param mixed $js_uri
	 * @return void
	 */
	public function addJqueryUI($component, $theme = 'base', $check_dependencies = true)
	{
		$ui_path = array();
		if (!is_array($component))
			$component = array($component);

		foreach ($component as $ui)
		{
			$ui_path = Media::getJqueryUIPath($ui, $theme, $check_dependencies);
			$this->addCSS($ui_path['css']);
			$this->addJS($ui_path['js']);
		}
	}

	/**
	 * Add a new javascript file in page header.
	 *
	 * @param mixed $name
	 * @param mixed $folder
	 * @return void
	 */
	public function addJqueryPlugin($name, $folder = null)
	{
		if (is_array($name))
		{
			foreach ($name as $plugin)
			{
				$plugin_path = Media::getJqueryPluginPath($plugin, $folder);
				if(!empty($plugin_path['js']))
					$this->addJS($plugin_path['js']);
				if(!empty($plugin_path['css']))		
					$this->addCSS($plugin_path['css']);
			}
		}
		else
		{
			$plugin_path = Media::getJqueryPluginPath($name, $folder);

			if(!empty($plugin_path['css']))
				$this->addCSS($plugin_path['css']);
			if(!empty($plugin_path['js']))
				$this->addJS($plugin_path['js']);
		}
	}

	/**
	 * @since 1.5
	 * @return bool return true if Controller is called from XmlHttpRequest
	 */
	public function isXmlHttpRequest()
	{
		return (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest');
	}
	
	protected function smartyOutputContent($content)
	{
		$this->context->cookie->write();
		if (is_array($content))
			foreach ($content as $tpl)
				$this->context->smarty->display($tpl);
		else
			$this->context->smarty->display($content);
	}
	
	protected function isCached($template, $cacheId = null, $compileId = null)
	{
		Tools::enableCache();
		$res = $this->context->smarty->isCached($template, $cacheId, $compileId);
		Tools::restoreCacheSettings();
		return $res;
	}

	public static function myErrorHandler($errno, $errstr, $errfile, $errline)
	{
	    if (!_PS_MODE_DEV_ || !(error_reporting() & $errno))
			return;
	    switch ($errno)
		{
		    case E_USER_ERROR:
				$type = 'Fatal error';
				break;
		    case E_USER_WARNING:
				$type = 'Warning';
		        break;
		    case E_USER_NOTICE:
				$type = 'Notice';
		        break;
		    default:
				$type = 'Unknow error';
		        break;
	    }

		Controller::$php_errors[] = array(
			'type' => $type, 
			'errline' => $errline, 
			'errfile' => $errfile, 
			'errno' => $errno, 
			'errstr' => $errstr
		);
		Context::getContext()->smarty->assign('php_errors', Controller::$php_errors);
	    return true;
	}
}
