<?php namespace SpellChecker\Driver;

//region Mocking global functions

function pspell_new($language, $spelling = null, $jargon = null, $encoding = null, $mode = 0)
{
	assert($spelling === null);
	assert($jargon === null);
	assert($mode === 0);

	return ($language === 'en') ? crc32($encoding) : false;
}

function pspell_config_create($language, $spelling = null, $jargon = null, $encoding = null)
{
	assert($spelling === null);
	assert($jargon === null);

	return in_array($language, array('de', 'en', 'vi')) ? compact('language', 'encoding') : false;//int
}

function pspell_config_data_dir(array &$conf, $directory, $key = 'data_dir')
{
	if (!empty($directory) && substr($directory, -1) == '/') {
		$conf[$key] = $directory;
		return true;
	}
	return false;
}

function pspell_config_dict_dir(array &$conf, $directory)
{
	return pspell_config_data_dir($conf, $directory, 'dict_dir');
}

function pspell_new_config(array $config)
{
	assert(isset($config['language']));
	assert(isset($config['encoding']));

	return isset($config['dict_dir']) ? crc32(var_export($config, true)) : false;
}

function pspell_suggest($dict_link, $word)
{
	assert(is_int($dict_link));

	return empty($word) ? array() : array((string)$word, $word . '+');
}

function pspell_check($dict_link, $word)
{
	assert(is_int($dict_link));

	return !empty($word) && ctype_alpha($word) && strcasecmp('baz', $word) != 0;
}

//endregion

class PSpellTest extends \PHPUnit_Framework_TestCase
{
	public function testConstructor()
	{
		$resourceProp = new \ReflectionProperty(PSpell::class, 'pspell_link');
		$resourceProp->setAccessible(true);

		$object = new PSpell();
		$this->assertEquals(crc32('utf-8'), $resourceProp->getValue($object));

		// constructor with custom encoding
		$object = new PSpell(array('encoding' => 'cp1252'));
		$this->assertEquals(crc32('cp1252'), $resourceProp->getValue($object));

		// constructor with dictionary path
		$object = new PSpell(array('lang' => 'de', 'dictionary' => $dir = __DIR__ . '/../../../../dictionary/'));

		$expected = array('language' => 'de', 'encoding' => 'utf-8', 'data_dir' => $dir, 'dict_dir' => $dir);
		$this->assertEquals(crc32(var_export($expected, true)), $resourceProp->getValue($object));
	}

	/**
	 * @expectedException \InvalidArgumentException
	 * @expectedExceptionMessage PSpell dictionary not found for lang: de
	 */
	public function testConstructorWithUnsupportedLang()
	{
		new PSpell(array('lang' => 'de'));
	}

	/**
	 * @expectedException \InvalidArgumentException
	 * @expectedExceptionMessage PSpell dictionary not found for lang: en
	 */
	public function testConstructorWithInvalidDictionaryPath()
	{
		new PSpell(array('lang' => 'en', 'dictionary' => __DIR__ . '/../../../../dictionary'));
	}

	public function testGetWordSuggestions()
	{
		$object = new PSpell();
		$this->assertEquals(array(), $object->get_suggestions(array('word' => ' ')));
		$this->assertEquals(array('baz+'), $object->get_suggestions(array('word' => 'baz ')));
		$this->assertEquals(array('foo+'), $object->get_suggestions(array('word' => ' foo ')));
	}

	public function testIsIncorrectWord()
	{
		$object = new PSpell();

		$expected = array('outcome' => 'success', 'data' => array());
		$this->assertEquals($expected, $object->get_incorrect_words(array('text' => array())));

		$expected['data'] = array(array('baz'), array(), array(), array(), array('bar-Foo1'));
		$this->assertEquals($expected, $object->get_incorrect_words(array('text' => array('baz bar-valid ', '', 'bar', null, ' bar-Foo1 in_valid'))));
	}
}
