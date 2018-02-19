<?php namespace SpellChecker\Driver;

class JsEngineTest extends \PHPUnit_Framework_TestCase
{
	private static function flushCache($return = false)
	{
		foreach (glob(sys_get_temp_dir() . '/PHPSpellCheck.*') as $file) {
			if ($return)
				return $file;
			else
				@unlink($file);
		}
		return null;
	}

	/**
	 * {@inheritDoc}
	 */
	public static function tearDownAfterClass()
	{
		parent::tearDownAfterClass();
		static::flushCache();
	}

	const MOCK_CLASS = 'SpellChecker\Driver\JsEngine';

	public function testConstructor()
	{
		$rc = new \ReflectionClass(static::MOCK_CLASS);
		$constructor = $rc->getConstructor();

		static::flushCache();
		$this->assertNull(static::flushCache(true));

		// get mock, without the constructor being called
		$methods = array('loadDictionary', 'loadCustomDictionary', 'loadCustomBannedWords', 'loadEnforcedCorrections', 'loadCommonTypos');
		$mock = $this->getMock(static::MOCK_CLASS, $methods, array(), '', false);

		// set expectations for constructor calls
		$mock->expects($this->once())->method('loadDictionary')->with('en');
		$mock->expects($this->once())->method('loadCustomDictionary')->with('custom.txt');
		$mock->expects($this->once())->method('loadCustomBannedWords')->with('rules/banned-words.txt');
		$mock->expects($this->once())->method('loadEnforcedCorrections')->with('rules/enforced-corrections.txt');
		$mock->expects($this->once())->method('loadCommonTypos')->with('rules/common-mistakes.txt');

		// now call the constructor
		$constructor->invoke($mock);

		$expected = (array)$mock;
		array_shift($expected);
		unset($mock);

		return $expected;
	}

	/**
	 * @depends testConstructor
	 * @param array $expected
	 */
	public function testDestructor($expected)
	{
		$this->assertNotEmpty($cacheFile = static::flushCache(true));
		$mockSize = filesize($cacheFile);

		// constructor from cache
		$object = (array)(new JsEngine());
		$this->assertEquals(array_intersect_key($expected, $object), $object);

		// destructor
		unset($object);
		$this->assertEquals($mockSize + 6 - 60, filesize($cacheFile));

		@unlink($cacheFile);
	}

	/**
	 * @expectedException \RuntimeException
	 * @expectedExceptionMessage Dictionary directory not found.
	 */
	public function testConstructorWithoutDictionaryPath()
	{
		new JsEngine(array('dictionaryPath' => __FILE__));
	}

	/**
	 * @expectedException \InvalidArgumentException
	 * @expectedExceptionMessage 'de' dictionary file not found.
	 */
	public function testConstructorWithUnsupportedLang()
	{
		new JsEngine(array('lang' => 'de'));
	}

	/**
	 * @expectedException \InvalidArgumentException
	 * @expectedExceptionMessage Custom dictionary file not found.
	 */
	public function testConstructorWithInvalidCentralDictionary()
	{
		static::flushCache();
		$this->getMock(static::MOCK_CLASS, array('loadDictionary'), array(array('centralDictionary' => 'notFound.txt')));
	}

	/**
	 * @expectedException \InvalidArgumentException
	 * @expectedExceptionMessage Custom banned-words file not found.
	 */
	public function testConstructorWithInvalidBannedWordsFile()
	{
		static::flushCache();
		$this->getMock(static::MOCK_CLASS,
			array('loadDictionary', 'loadCustomDictionary'),
			array(array('bannedWordsFile' => 'notFound.txt'))
		);
	}

	/**
	 * @expectedException \InvalidArgumentException
	 * @expectedExceptionMessage Enforced corrections file not found.
	 */
	public function testConstructorWithInvalidEnforcedCorrectionsFile()
	{
		static::flushCache();
		$this->getMock(static::MOCK_CLASS,
			array('loadDictionary', 'loadCustomDictionary', 'loadCustomBannedWords'),
			array(array('enforcedCorrectionsFile' => 'notFound.txt'))
		);
	}

	/**
	 * @expectedException \InvalidArgumentException
	 * @expectedExceptionMessage Common typos file not found.
	 */
	public function testConstructorWithInvalidCommonMistakesFile()
	{
		static::flushCache();
		$this->getMock(static::MOCK_CLASS,
			array('loadDictionary', 'loadCustomDictionary', 'loadCustomBannedWords', 'loadEnforcedCorrections'),
			array(array('commonMistakesFile' => 'notFound.txt'))
		);
	}

	/**
	 * @requires PHP 7.1
	 */
	public function testBuildDictionary()
	{
		$str = @file_get_contents(__DIR__ . '/../../../../dictionary/jspell/en.dic');
		$this->assertNotEmpty($str);

		static $delimit = "\n==========================================\n";
		$this->assertGreaterThan(0, $i = strpos($str, $delimit));

		static $smallDelimit = "+++++++++\n";
		$str = str_replace($smallDelimit, '', substr($str, 0, $i));

		$wordsDict = explode("\n", $str);
		unset($str);

		$this->assertCount(112428, $wordsDict);
		$this->assertEquals('mood', $wordsDict[72357]);

		$mock = $this->getMock(static::MOCK_CLASS, null, array(), '', false);
		/* @var $mock \PHPUnit_Framework_MockObject_MockObject|JsEngine */
		$result = $mock->buildDictionary($wordsDict);
		unset($wordsDict);

		$this->assertNotEmpty($result);
		$this->assertEquals($i, $p = strpos($result, $delimit));

		$result = substr($result, $p + ($l = strlen($delimit) - 1), -$l);
		$this->assertContains("\n00R#t1711\n", $result);
		$this->assertContains("\nXXT#c3055|s3543\n", $result);
	}

	public function testGetWordSuggestions()
	{
		$object = new JsEngine();
		$object->setContext(array('biz', 'baize', 'fee', 'fee'));

		$this->assertEquals(array(), $object->get_suggestions(array('word' => '')));
		$this->assertEquals(array('biz', 'baize', 'Baez'), $object->get_suggestions(array('word' => 'baz ')));
		$this->assertEquals(array('foe', 'fee', 'fro'), $object->get_suggestions(array('word' => ' foo ')));

		// enforced words
		$this->assertEquals(array('because'), $object->get_suggestions(array('word' => " cos\n")));
		// correct case
		$this->assertEquals(array('Vietcong'), $object->get_suggestions(array('word' => "\tVieTCong")));
		$this->assertEquals(array('pit'), $object->get_suggestions(array('word' => 'pIt ')));
		// common typos
		$this->assertEquals(array('a lot', 'Lot', 'alto', 'Aleut', 'alt'), $object->get_suggestions(array('word' => ' ALot')));
	}

	public function testIsIncorrectWord()
	{
		$object = new JsEngine();

		$expected = array('outcome' => 'success', 'data' => array());
		$this->assertEquals($expected, $object->get_incorrect_words(array('text' => array())));

		$expected['data'] = array(array('baz'), array(), array(), array(), array('bar-foo', 'cos'));
		$this->assertEquals($expected, $object->get_incorrect_words(array('text' => array('baz bar-valid ', '', 'bar FOO', null, ' bar-foo in_valid 123 cos'))));
	}

	private static function invokeMethod($object, $methodName, $_ = null)
	{
		$method = new \ReflectionMethod(static::MOCK_CLASS, $methodName);
		$method->setAccessible(true);
		return $method->invokeArgs($object, array_slice(func_get_args(), 2));
	}

	private static function writeAttribute($object, $attributeName, $value)
	{
		$attribute = new \ReflectionProperty(static::MOCK_CLASS, $attributeName);
		$attribute->setAccessible(true);
		$attribute->setValue($object, $value);
	}

	public function testPrivateMethods()
	{
		$mock = $this->getMock(static::MOCK_CLASS, null, array(), '', false);

		$this->writeAttribute($mock, '_dictArray', $expected = array('de' => array()));
		$this->invokeMethod($mock, 'loadDictionary', 'de');
		$this->assertAttributeEquals($expected, '_dictArray', $mock);

		$this->invokeMethod($mock, 'decipherStrDict', 'de', null);
		$this->assertAttributeEquals($expected, '_dictArray', $mock);

		$result = $this->invokeMethod($mock, 'loadCustomDictionary', '');
		$this->assertFalse($result);

		$this->invokeMethod($mock, 'loadCustomBannedWords', null);
		$this->assertAttributeEmpty('bannedWords', $mock);

		$this->invokeMethod($mock, 'loadEnforcedCorrections', false);
		$this->assertAttributeEmpty('bannedWords', $mock);
		$this->assertAttributeEmpty('enforcedWords', $mock);

		$this->invokeMethod($mock, 'loadCommonTypos', 0);
		$this->assertAttributeEmpty('commonTypos', $mock);

		$result = $this->invokeMethod($mock, 'inArrayIgnoreCase', null, '');
		$this->assertFalse($result);
	}
}
