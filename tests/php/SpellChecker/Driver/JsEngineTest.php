<?php namespace SpellChecker\Driver;

class JsEngineTest extends \PHPUnit_Framework_TestCase
{
	const DICT_PATH = '/../../../../dictionary/jspell/';

	private static function flushCache()
	{
		foreach (scandir($path = __DIR__ . static::DICT_PATH) as $file) {
			if (preg_match('/^[a-z]{2}(?:_[A-Z]{2})?\.[A-F0-9a-f]{32}$/', $file))
				@unlink($path . $file);
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

	private static function hasCache($lang = 'en')
	{
		foreach (scandir($path = __DIR__ . static::DICT_PATH) as $file) {
			if (preg_match('/^' . $lang . '\.[A-F0-9a-f]{32}$/', $file))
				return $path . $file;
		}
		return false;
	}

	public function testConstructor()
	{
		$constructor = (new \ReflectionClass(JsEngine::class))->getConstructor();

		static::flushCache();
		$this->assertFalse(static::hasCache());

		// get mock, without the constructor being called
		$methods = array('loadDictionary', 'loadCustomDictionary', 'loadCustomBannedWords', 'loadEnforcedCorrections', 'loadCommonTypos');
		$mock = $this->getMock(JsEngine::class, $methods, array(), '', false);

		// set expectations for constructor calls
		$mock->expects($this->once())->method('loadDictionary')->with($this->equalTo('en'));
		$mock->expects($this->once())->method('loadCustomDictionary')->with($this->equalTo('custom.txt'));
		$mock->expects($this->once())->method('loadCustomBannedWords')->with($this->equalTo('rules/banned-words.txt'));
		$mock->expects($this->once())->method('loadEnforcedCorrections')->with($this->equalTo('rules/enforced-corrections.txt'));
		$mock->expects($this->once())->method('loadCommonTypos')->with($this->equalTo('rules/common-mistakes.txt'));

		// now call the constructor
		$constructor->invoke($mock);
		$this->assertNotEmpty($cacheFile = static::hasCache());

		// constructor from cache
		$object = (array)(new JsEngine());
		$this->assertEquals(array_intersect_key((array)$mock, $object), $object);

		@unlink($cacheFile);
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
		$this->getMock(JsEngine::class, array('loadDictionary'), array(array('centralDictionary' => 'notFound.txt')));
	}

	/**
	 * @expectedException \InvalidArgumentException
	 * @expectedExceptionMessage Custom banned-words file not found.
	 */
	public function testConstructorWithInvalidBannedWordsFile()
	{
		static::flushCache();
		$this->getMock(JsEngine::class,
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
		$this->getMock(
			JsEngine::class,
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
		$this->getMock(
			JsEngine::class,
			array('loadDictionary', 'loadCustomDictionary', 'loadCustomBannedWords', 'loadEnforcedCorrections'),
			array(array('commonMistakesFile' => 'notFound.txt'))
		);
	}

	/**
	 * @requires PHP 7.1
	 */
	public function testBuildDictionary()
	{
		$str = @file_get_contents(__DIR__ . static::DICT_PATH . 'en.dic');
		$this->assertNotEmpty($str);

		static $delimit = "\n==========================================\n";
		$this->assertGreaterThan(0, $i = strpos($str, $delimit));

		static $smallDelimit = "+++++++++\n";
		$str = str_replace($smallDelimit, '', substr($str, 0, $i));

		$wordsDict = explode("\n", $str);
		unset($str);

		$this->assertCount(112428, $wordsDict);
		$this->assertEquals('mood', $wordsDict[72357]);

		$mock = $this->getMock(JsEngine::class, null, array(), '', false);
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
		$this->assertEquals(array(), $object->get_suggestions(array('word' => '')));
		$this->assertEquals(array('biz', 'Baez', 'baize'), $object->get_suggestions(array('word' => 'baz ')));
		$this->assertEquals(array('foe', 'fro', 'fee'), $object->get_suggestions(array('word' => ' foo ')));
	}

	public function testIsIncorrectWord()
	{
		$object = new JsEngine();

		$expected = array('outcome' => 'success', 'data' => array());
		$this->assertEquals($expected, $object->get_incorrect_words(array('text' => array())));

		$expected['data'] = array(array('baz'), array(), array(), array(), array('bar-foo'));
		$this->assertEquals($expected, $object->get_incorrect_words(array('text' => array('baz bar-valid ', '', 'bar', null, ' bar-foo in_valid'))));
	}
}
