<?php namespace SpellChecker\Driver;

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
		try {
			new Google();
		} catch (\Exception $e) {
			static::$LOADED = true;
			throw $e;
		}
	}

}
