<?php namespace SpellChecker\Driver;

/**
 * Spellchecker Google driver class
 * !! Curl is required to use the google spellchecker API !!
 *
 * @package    jQuery Spellchecker (https://github.com/badsyntax/jquery-spellchecker)
 * @author     Richard Willis
 * @copyright  (c) Richard Willis
 * @license    https://github.com/badsyntax/jquery-spellchecker/blob/master/LICENSE-MIT
 */
class Google extends \SpellChecker\Driver
{
	protected $_default_config = array(
		'encoding' => 'utf-8',
		'lang' => 'en'
	);

	protected $http;

	private $_cache = array();
	private $_cache_file;

	public function __construct($config = array())
	{
		parent::__construct($config);

		if (!function_exists('curl_init') || ($ch = @curl_init()) === false) {
			throw new \RuntimeException('cURL is not available.');
		}

		curl_setopt($ch, CURLOPT_HEADER, false);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
		curl_setopt($ch, CURLOPT_TIMEOUT, 10);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
		/** @noinspection SpellCheckingInspection */
		curl_setopt($this->http = $ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.3; rv:36.0) Gecko/20100101 Firefox/36.0');

		$this->_cache_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'GSpell.' . md5(serialize($this->_config));
		if (file_exists($this->_cache_file)) {
			$this->_cache = @unserialize(@file_get_contents($this->_cache_file));
		}
	}

	public function __destruct()
	{
		if (isset($this->http))
			@curl_close($this->http);

		if (!empty($this->_cache))
			@file_put_contents($this->_cache_file, serialize($this->_cache));
	}

	protected function get_word_suggestions($word)
	{
		$matches = $this->get_matches($word);

		if (!empty($matches)) {
			sort($matches);
		}

		return $matches;
	}

	protected function is_incorrect_word($word)
	{
		$words = $this->get_matches($word);

		return empty($words) || !preg_grep('/^' . preg_quote($word, '/') . '$/i', $words);
	}

	private function get_matches($text)
	{
		if (empty($text))
			return array();

		if (isset($this->_cache[$cacheKey = strtolower($text)]))
			return $this->_cache[$cacheKey];

		// use Google suggestion service
		$url = 'https://www.google.com/complete/search?output=toolbar&hl=' . $this->_config['lang'] . '&q=' . urlencode($text);
		curl_setopt($this->http, CURLOPT_URL, $url);

		$xml_response = curl_exec($this->http);
		if ($xml_response === false)
			throw new \UnexpectedValueException('cURL error: ' . curl_error($this->http));

		$xml = @simplexml_load_string($xml_response = trim($xml_response));
		if ($xml === false || strcasecmp('topLevel', $xml->getName()) != 0)
			throw new \InvalidArgumentException(static::strip_html($xml_response));

		$matches = array();
		foreach ($xml->xpath('//suggestion[@data]') as $node) {

			$word = (string)$node['data'];
			if (!preg_match('/\s+/u', $word))
				$matches[] = $word;
		}

		return $this->_cache[$cacheKey] = array_unique($matches);
	}

	private static function strip_html($html)
	{
		foreach (array('head', 'title', 'style', 'script') as $tag) {
			$html = preg_replace('#\s*<' . $tag . '(\s[^>]*)?>.*?</' . $tag . '>#sui', '', $html);
		}
		return trim(strip_tags($html)) ?: $html;
	}
}
