<?php namespace SpellChecker;

class RequestTest extends \PHPUnit_Framework_TestCase
{
	public function testGetDriverClass()
	{
		$method = new \ReflectionMethod('SpellChecker\Request', 'get_driver_class');
		$method->setAccessible(true);

		$expected = 'PSpell';
		$this->assertEquals($expected, $method->invoke(null));
		$this->assertEquals($expected, $method->invoke(null, null));
		$this->assertEquals($expected, $method->invoke(null, array()));
		$this->assertEquals($expected, $method->invoke(null, array('driver' => null)));
		$this->assertEquals($expected, $method->invoke(null, array('driver' => '')));

		$expected = 'Bar';
		$this->assertEquals($expected, $method->invoke(null, null, $expected));
		$this->assertEquals($expected, $method->invoke(null, array(), $expected));
		$this->assertEquals($expected, $method->invoke(null, array('driver' => null), $expected));
		$this->assertEquals($expected, $method->invoke(null, array('driver' => ''), $expected));

		$this->assertEquals($expected, $method->invoke(null, array('driver' => 'bar')));

		$expected = 'BarSpell';
		$this->assertEquals($expected, $method->invoke(null, array('driver' => lcfirst($expected))));
		$this->assertEquals($expected, $method->invoke(null, array('driver' => strtolower($expected))));
		$this->assertEquals($expected, $method->invoke(null, array('driver' => strtoupper($expected))));

		$expected = 'BarEngine';
		$this->assertEquals($expected, $method->invoke(null, array('driver' => lcfirst($expected))));
		$this->assertEquals($expected, $method->invoke(null, array('driver' => strtolower($expected))));
		$this->assertEquals($expected, $method->invoke(null, array('driver' => strtoupper($expected))));

		/** @noinspection SpellCheckingInspection */
		$expected = 'Barfoo';
		$this->assertEquals($expected, $method->invoke(null, array('driver' => strtolower($expected))));
		$this->assertEquals($expected, $method->invoke(null, array('driver' => strtoupper($expected))));
		$this->assertEquals($expected, $method->invoke(null, array('driver' => lcfirst(strtoupper($expected)))));
	}

	/**
	 * @expectedException \PHPUnit_Framework_Error_Notice
	 * @expectedExceptionMessage Undefined index: action
	 */
	public function testExecuteActionWithoutAction()
	{
		Request::execute_action();
	}

	/**
	 * @expectedException \InvalidArgumentException
	 * @expectedExceptionMessage Not supported driver: BarSpell
	 */
	public function testExecuteActionWithInvalidDriver()
	{
		Request::execute_action(array('driver' => 'barSPELL'));
	}

	/**
	 * @expectedException \BadMethodCallException
	 * @expectedExceptionMessage Not supported action PSpell::barFoo()
	 */
	public function testExecuteActionWithInvalidAction()
	{
		Request::execute_action(array('action' => 'barFoo'));
	}

	/**
	 * @expectedException \BadMethodCallException
	 * @expectedExceptionMessage Not supported action PSpell::()
	 */
	public function testExecuteActionWithNullAction()
	{
		Request::execute_action(array('action' => null));
	}

	/**
	 * @expectedException \BadMethodCallException
	 * @expectedExceptionMessage Not supported action PSpell::()
	 */
	public function testExecuteActionWithEmptyAction()
	{
		Request::execute_action(array('action' => ''));
	}

	const MOCK_CLASS = 'SpellChecker\Driver\BarSpell';

	private static function mockSpellClass()
	{
		if (!\class_exists(static::MOCK_CLASS)) {
			eval('namespace SpellChecker\Driver {
	class BarSpell {
		function __construct() { $this->args = func_get_args(); }
		function fooBar() { $this->params = func_get_args(); return $this; }
	}
}');
		}
	}

	public function testExecuteAction()
	{
		static::mockSpellClass();

		/** @noinspection SpellCheckingInspection */
		$inputs = array('driver' => 'BARspell', 'action' => 'fooBar');
		$mock = Request::execute_action($inputs);
		//
		$this->assertTrue(is_object($mock) && static::MOCK_CLASS === get_class($mock));
		$this->assertEquals(array($inputs), $mock->params);
		$this->assertEquals(array(array('lang' => 'en')), $mock->args);

		$inputs['lang'] = '';
		$inputs['word'] = 'baz';
		$mock = Request::execute_action($inputs, array('lang' => 'de', 'dir' => false));
		//
		$this->assertTrue(is_object($mock) && static::MOCK_CLASS === get_class($mock));
		$this->assertEquals(array($inputs), $mock->params);
		$this->assertEquals(array(array('lang' => '', 'dir' => false)), $mock->args);
	}

	public function testConstructor()
	{
		if (!isset($_POST)) $_POST = array();

		$this->expectOutputString(null);
		new Request();
	}

	public function testSendResponse()
	{
		static::mockSpellClass();
		// Mock global functions
		if (!\function_exists('SpellChecker\header')) {
			eval('namespace SpellChecker { function header($s) { echo $s . PHP_EOL; } }');
		}

		$inputs = array('driver' => 'BARSpell', 'action' => 'fooBar');
		$this->expectOutputString('Content-type: application/json' . PHP_EOL
			. json_encode(array('args' => array(array('lang' => 'en')), 'params' => array($inputs)))
		);
		new Request($inputs);
	}
}
