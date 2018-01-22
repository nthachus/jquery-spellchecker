<?php namespace SpellChecker\Driver;

//region Mocking global functions

function function_exists($name)
{
	return ($name === 'enchant_broker_init' && EnchantTest::$LOADED)
		|| ($name === 'pspell_new' && PSpellTest::$LOADED);
}

function enchant_broker_free_dict(&$dict)
{
	//echo 'free-dict@' . var_export($dict, true) . PHP_EOL;
	if (is_int($dict)) {
		$dict = null;
		return true;
	}
	return false;
}

function enchant_broker_free(&$broker)
{
	assert(is_int($broker));
	//echo 'free-broker@' . $broker . PHP_EOL;
	$broker = null;
	return true;
}

function enchant_broker_init()
{
	return mt_rand();
}

function enchant_broker_dict_exists($broker, $tag)
{
	assert(is_int($broker));
	return in_array($tag, array('en', 'en_US'));
}

function enchant_broker_request_dict($broker, $tag)
{
	assert(is_int($broker));
	return ($tag !== 'en') ? true : crc32($tag) ^ $broker;
}

function enchant_dict_suggest($dict, $word)
{
	assert(is_int($dict));
	return empty($word) ? array() : array((string)$word, $word . '~');
}

function enchant_dict_check($dict, $word)
{
	assert(is_int($dict));
	return !empty($word) && ctype_lower($word) && strcasecmp('baz', $word) != 0;
}

//endregion

class EnchantTest extends \PHPUnit_Framework_TestCase
{
	static $LOADED = true;

	/**
	 * @expectedException \RuntimeException
	 * @expectedExceptionMessage Enchant library not found.
	 */
	public function testNoLibrary()
	{
		static::$LOADED = false;
		try {
			new Enchant();
		} catch (\Exception $e) {
			static::$LOADED = true;
			throw $e;
		}
	}

	public function testConstructor()
	{
		$brokerProp = new \ReflectionProperty(Enchant::class, 'broker');
		$brokerProp->setAccessible(true);

		$dictProp = new \ReflectionProperty(Enchant::class, 'dictionary');
		$dictProp->setAccessible(true);

		$object = new Enchant();
		$this->assertEquals(crc32('en') ^ $brokerProp->getValue($object), $dictProp->getValue($object));

		// constructor with custom language
		$object = new Enchant(array('lang' => 'en_US'));
		$this->assertTrue($dictProp->getValue($object));
	}

	/**
	 * @expectedException \InvalidArgumentException
	 * @expectedExceptionMessage Enchant dictionary not found for lang: de
	 */
	public function testConstructorWithUnsupportedLang()
	{
		new Enchant(array('lang' => 'de'));
	}

	public function testGetWordSuggestions()
	{
		$object = new Enchant();
		$this->assertEquals(array(), $object->get_suggestions(array('word' => ' ')));
		$this->assertEquals(array('baz~'), $object->get_suggestions(array('word' => 'baz ')));
		$this->assertEquals(array('foo~'), $object->get_suggestions(array('word' => ' foo ')));
	}

	public function testIsIncorrectWord()
	{
		$object = new Enchant();

		$expected = array('outcome' => 'success', 'data' => array());
		$this->assertEquals($expected, $object->get_incorrect_words(array('text' => array())));

		$expected['data'] = array(array('baz'), array(), array(), array(), array('bar-Foo'));
		$this->assertEquals($expected, $object->get_incorrect_words(array('text' => array('baz bar-valid ', '', 'bar', null, ' bar-Foo in_valid'))));
	}
}
