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

	public function testConstructor()
	{
		$constructor = (new \ReflectionClass(JsEngine::class))->getConstructor();

		static::flushCache();
		$this->assertNull(static::flushCache(true));

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

		// destructor
		$p = new \ReflectionProperty(\PHPUnit_Framework_TestCase::class, 'mockObjects');
		$p->setAccessible(true);
		$this->assertCount(1, $p->getValue($this));
		$p->setValue($this, array());
		//
		$mock->{'__phpunit_cleanup'}();
		$expected = (array)$mock;
		unset($mock);

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
