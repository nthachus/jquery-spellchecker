<?php namespace SpellChecker\Driver;

//region Mocking global functions

function curl_exec($ch)
{
	return (GoogleTest::$LOADED === true) ? \curl_exec($ch) : GoogleTest::$LOADED;
}

function curl_error($ch)
{
	return GoogleTest::$LOADED ? \curl_error($ch) : 'Faked';
}

//endregion

class GoogleTest extends \PHPUnit_Framework_TestCase
{
	static $LOADED = true;

	/**
	 * @expectedException \RuntimeException
	 * @expectedExceptionMessage cURL is not available.
	 */
	public function testNoLibrary()
	{
		static::$LOADED = false;
		new Google();
	}

	/**
	 * {@inheritDoc}
	 */
	protected function tearDown()
	{
		parent::tearDown();
		static::$LOADED = true;
	}

	private static function flushCache()
	{
		foreach (glob(sys_get_temp_dir() . '/GSpell.*') as $file) {
			@unlink($file);
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public static function tearDownAfterClass()
	{
		parent::tearDownAfterClass();
		static::flushCache();
	}

	private static function invokeGetMatches($object, $text = '')
	{
		static $method;
		if (!isset($method)) {
			$method = new \ReflectionMethod(Google::class, 'get_matches');
			$method->setAccessible(true);
		}

		return $method->invoke($object, $text);
	}

	/**
	 * @expectedException \UnexpectedValueException
	 * @expectedExceptionMessage cURL error: Faked
	 */
	public function testGetMatchesWithHttpError()
	{
		static::flushCache();
		$object = new Google();

		static::$LOADED = false;
		static::invokeGetMatches($object, 'foo');
	}

	/**
	 * @expectedException \InvalidArgumentException
	 * @expectedExceptionMessage A_A
	 */
	public function testGetMatchesWithXmlError()
	{
		static::flushCache();
		$object = new Google();

		static::$LOADED = ' <root><x>A_A</x></root>';
		static::invokeGetMatches($object, 'any');
	}

	/**
	 * @expectedException \InvalidArgumentException
	 * @expectedExceptionMessage Failed!
	 */
	public function testGetMatchesWithHtmlError()
	{
		static::flushCache();
		$object = new Google();

		static::$LOADED = ' <h3>Failed!</h3><script>void();</script> ';
		static::invokeGetMatches($object, 'any');
	}

	public function testGetWordSuggestions()
	{
		$object = new Google();
		$this->assertEquals(array(), $object->get_suggestions(array('word' => ' ')));

		/** @noinspection SpellCheckingInspection */
		$expected = array('baza', 'bazaar', 'bazar', 'bazel', 'bazi', 'bazinga', 'bazo', 'bazooka');
		$this->assertEquals($expected, $object->get_suggestions(array('word' => 'baz ')));

		/** @noinspection SpellCheckingInspection */
		$expected = array('food', 'foodie', 'foody', 'football', 'footlocker');
		$this->assertEquals($expected, $object->get_suggestions(array('word' => ' foo ')));
	}

	public function testIsIncorrectWord()
	{
		$object = new Google();

		$expected = array('outcome' => 'success', 'data' => array());
		$this->assertEquals($expected, $object->get_incorrect_words(array('text' => array())));

		$expected['data'] = array(array('baz'), array(), array(), array(), array('bar-foo'));
		$this->assertEquals($expected, $object->get_incorrect_words(array('text' => array('baz bar-valid ', ' ', 'bar', null, ' bar-foo in_valid'))));
	}
}
