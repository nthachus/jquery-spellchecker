<?php namespace SpellChecker;

/**
 * Spellchecker request class
 *
 * @package    jQuery Spellchecker (https://github.com/badsyntax/jquery-spellchecker)
 * @author     Richard Willis
 * @copyright  (c) Richard Willis
 * @license    https://github.com/badsyntax/jquery-spellchecker/blob/master/LICENSE-MIT
 */
class Request {

	public function __construct($inputs = array(), $config = array())
	{
		if (empty($inputs))
			$inputs = $_POST;

		if (empty($inputs['action']))
			return;

		$response = static::execute_action($inputs, $config);
		static::send_response($response);
	}

	public static function execute_action($inputs = array(), $config = array())
	{
		$lang = isset($inputs['lang']) ? $inputs['lang'] : 'en';

		$class = '\SpellChecker\Driver\\' . static::get_driver_class($inputs);
		$driver = new $class(array_merge($config, compact('lang')));

		return $driver->{$inputs['action']}($inputs);
	}

	private static function get_driver_class($inputs = array(), $default = 'PSpell')
	{
		if (empty($inputs['driver']))
			return $default;

		$driver = ucfirst(strtolower($inputs['driver']));
		return str_replace(array('spell', 'engine'), array('Spell', 'Engine'), $driver);
	}

	public static function send_response($data)
	{
		header('Content-type: application/json');

		echo json_encode($data);
	}
}