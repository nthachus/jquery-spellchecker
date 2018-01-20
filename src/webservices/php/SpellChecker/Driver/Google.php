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

	public function __construct($config = array())
	{
		parent::__construct($config);

		if (!function_exists('curl_init')) {
			throw new \RuntimeException('cURL is not available.');
		}
	}

	protected function get_word_suggestions($word)
	{
		$matches = $this->get_matches($word);

		if (isset($matches[0][3]) && ($s = trim($matches[0][3]))) {
			return explode("\t", $s);
		}

		return array();
	}

	public function get_incorrect_words($inputs = array())
	{
		$texts = isset($inputs['text']) ? (array)$inputs['text'] : array();

		$response = array();
		foreach ($texts as $text) {
			$words = $this->get_matches($text);

			$incorrect_words = array();
			foreach ($words as $word) {
				$incorrect_words[] = mb_substr($text, $word[0], $word[1], $this->_config['encoding']);
			}

			$response[] = array_unique(array_filter($incorrect_words));
		}

		return $this->send_data(array_filter($response), 'success');
	}

	protected function is_incorrect_word($word)
	{
	}

	private function get_matches($text)
	{
		// TODO: use Google suggestion service
		$url = 'https://www.google.com/tbproxy/spell?lang=' . $this->_config['lang'];
		/** @noinspection SpellCheckingInspection */
		$body = '<?xml version="1.0" encoding="' . $this->_config['encoding']
			. '"?><spellrequest textalreadyclipped="0" ignoredups="0" ignoredigits="1" ignoreallcaps="0"><text>'
			. htmlspecialchars($text, ENT_NOQUOTES, $this->_config['encoding']) . '</text></spellrequest>';

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HEADER, false);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
		curl_setopt($ch, CURLOPT_TIMEOUT, 10);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

		$xml_response = curl_exec($ch);
		if ($xml_response === false)
			$error = 'cURL error: ' . curl_error($ch);

		curl_close($ch);
		if (isset($error))
			throw new \UnexpectedValueException($error);

		$xml = @simplexml_load_string($xml_response);
		if (!isset($xml->c))
			throw new \InvalidArgumentException(empty($xml) ? static::strip_html($xml_response) : $xml);

		$matches = array();
		foreach ($xml->c as $word) {

			$attributes = $word->attributes();
			$matches[] = array(
				intval($attributes->o),
				intval($attributes->l),
				intval($attributes->s),
				(string)$word
			);
		}

		return $matches;
	}

	private static function strip_html($html)
	{
		foreach (array('head', 'title', 'style', 'script') as $tag) {
			$html = preg_replace('#\s*<' . $tag . '(\s[^>]*)?>.*?</' . $tag . '>#sui', '', $html);
		}
		return trim(strip_tags($html));
	}
}
