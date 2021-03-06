<?php

namespace nitm\helpers;

use yii\db\ActiveRecord;
use yii\base\Model;
use yii\helpers\Inflector;


class ClassHelper extends Model
{

	/*
	 * Return a string imploded with ucfirst characters
	 * @param string $name
	 * @return string
	 */
	public static function variableName($value)
	{
		return Inflector::variablize($value);
	}

	/*
	 * Return a string imploded with ucfirst characters
	 * @param string $name
	 * @return string
	 */
	public static function properName($value)
	{
		return Inflector::humanize(str_replace('-', '_', $value), true);
	}

	/*
	 * Return a string imploded with ucfirst characters with no spaces
	 * @param string $name
	 * @return string
	 */
	public static function properFormName($value)
	{
		return Inflector::camelize($value);
	}

	/*
	 * Return a string imploded with ucfirst characters with no spaces
	 * @param string $name
	 * @return string
	 */
	public static function properModelName($value)
	{
		return Inflector::camelize($value);
	}

	/*
	 * Return a string imploded with ucfirst characters
	 * @param string $name
	 * @return string
	 */
	public static function properClassName($value, $namespace=null)
	{
		$ret_val = is_null($value) ?  static::className() : preg_replace('/[\-\_]/', " ", $value);
		$ret_val = implode('', array_map('ucfirst', explode(' ', static::properName($ret_val))));
		if($namespace)
			return rtrim($namespace).'\\'.$ret_val;
		return $ret_val;
	}

	public static function getNamespace($className)
	{
		return (new \ReflectionClass($className))->getNamespaceName();
	}
}
?>
