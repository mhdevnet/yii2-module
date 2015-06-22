<?php
namespace nitm\traits;

use nitm\helpers\Session;
use nitm\helpers\Helper;
use nitm\helpers\Cache as CacheHelper;

 /**
  * Configuration traits that can be shared
  */
trait Configer {
	
	public static $settings = [];
	
	/**
	 * Get a setting value 
	 * @param string $setting the locator for the setting
	 */
	public static function setting($setting=null)
	{
		$hierarchy = explode('.', $setting);
		switch($hierarchy[0])
		{
			case '@':
			array_pop($hierarchy[0]);
			break;
			
			case static::isWhat():
			case null:
			$hierarchy = sizeof($hierarchy) == 1 ? static::isWhat() : $hierarchy;
			break;
			
			default:
			array_unshift($hierarchy, static::isWhat());
			break;
		}
		@eval("\$ret_val = static::\$settings['".Helper::splitf($hierarchy, "']['")."'];");
		return $ret_val;
	}
	
	/*
	 * Initialize configuration
	 * @param string $container
	 */
	public static function initConfig($container=null)
	{
		$module = \Yii::$app->getModule('nitm');
		$container = is_null($container) ? $module->config->container : $container;
		switch(1)
		{
			case !CacheHelper::cache()->exists('config-'.$container):
			case CacheHelper::cache()->exists('config-'.$container) && (count(CacheHelper::cache()->get('config-'.$container) == 0)):
			case !isset(static::$settings[$container]):
			case ($container == $module->config->container) && (!Session::isRegistered(Session::settings)):
			$module->config->setEngine($module->config->engine);
			$module->config->setType($module->config->engine, $container);
			switch($module->config->engine)
			{
				case 'file':
				$module->setDir($module->config->dir);
				break;
			}
			switch(1)
			{
				case Session::isRegistered(Session::current.'.'.$container):
				static::$settings[$container] = Session::getval(Session::current.'.'.$container);
				break;
				
				default:
				switch(1)
				{
					case CacheHelper::cache()->exists('config-'.$container) && count(CacheHelper::cache()->get('config-'.$container)):
					$config = CacheHelper::cache()->get('config-'.$container);
					Session::set(Session::current.'.'.$container, $config);
					static::$settings[$container] = $config;
					break;
					
					case ($container == $module->config->container) && (!Session::isRegistered(Session::settings)):
					$config = $module->config->getConfig($module->config->engine, $container, true);
					Session::set(Session::settings, $config);
					break;
					
					case ($container != $module->config->container) && !isset(static::$settings[$container]):
					$config = $module->config->getConfig($module->config->engine, $container, true);
					CacheHelper::cache()->set('config-'.$container, $config, 120);
					Session::set(Session::current.'.'.$container, $config);
					static::$settings[$container] = $config;
					break;
				}
				break;
			}
		}
	}
}
?>