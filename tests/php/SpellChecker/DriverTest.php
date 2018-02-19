<?php namespace SpellChecker;

class DriverTest extends \PHPUnit_Framework_TestCase
{
	/**
	 * @var \PHPUnit_Framework_MockObject_MockObject|\SpellChecker\Driver
	 */
	protected $mock;

	protected function setUp()
	{
		parent::setUp();

		// create a mock for the abstract class
		$this->mock = $this->getMockForAbstractClass('SpellChecker\Driver');
	}

	public function testConstructor()
	{
		// call the default constructor
		$this->assertAttributeEquals(array(), '_config', $this->mock);

		// constructor with custom configurations
		$mock = $this->getMockForAbstractClass('SpellChecker\Driver', array(array('lang' => null, 'path' => '', 'var' => false)));
		$this->assertAttributeEquals(array('var' => false), '_config', $mock);
	}

	public function testGetSuggestionsWithEmptyWord()
	{
		$this->assertEquals(array(), $this->mock->get_suggestions());
		$this->assertEquals(array(), $this->mock->get_suggestions(array('word' => null)));
		$this->assertEquals(array(), $this->mock->get_suggestions(array('word' => '')));
	}

	public function testGetSuggestions()
	{
		$this->mock->expects($this->once())
			->method('get_word_suggestions')
			->with('baz')
			->will($this->returnValue(array('baz', 'bar')));

		$this->assertEquals(array('bar'), $this->mock->get_suggestions(array('word' => 'baz ')));
	}

	public function testGetIncorrectWordsWithEmptyText()
	{
		$expected = array('outcome' => 'success', 'data' => array());
		$this->assertEquals($expected, $this->mock->get_incorrect_words());
		$this->assertEquals($expected, $this->mock->get_incorrect_words(array('text' => null)));
		$this->assertEquals($expected, $this->mock->get_incorrect_words(array('text' => array())));

		array_push($expected['data'], array());
		$this->assertEquals($expected, $this->mock->get_incorrect_words(array('text' => '')));
		array_push($expected['data'], array());
		$this->assertEquals($expected, $this->mock->get_incorrect_words(array('text' => array(null, ''))));
	}

	public function testGetIncorrectWords()
	{
		$this->mock->expects($this->any())
			->method('is_incorrect_word')
			->will($this->returnValueMap(array(
				array('baz', true),
				array('bar-valid', false),
				array('bar', false),
				array('bar-Foo', true),
				array('barFoo', true),
				array('Foo', true),
				array('in_valid', true),
				array('invalid', false),
			)));

		$result = $this->mock->get_incorrect_words(array('text' => array('baz bar-valid ', '', 'bar', null, ' bar-Foo in_valid')));

		$expected = array(array('baz'), array(), array(), array(), array('bar-Foo'));
		$this->assertEquals(array('outcome' => 'success', 'data' => $expected), $result);
	}
}
