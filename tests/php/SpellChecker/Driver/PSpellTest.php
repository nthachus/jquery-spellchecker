<?php namespace SpellChecker\Driver;

//region Mocking global functions

function pspell_new($language, $spelling = null, $jargon = null, $encoding = null, $mode = 0)
{
	assert($spelling === null);
	assert($jargon === null);
	assert($mode === 0);

	return ($language === 'en') ? crc32($encoding) : false;
}

//array pspell_config_create
//pspell_config_data_dir
//pspell_config_dict_dir
//pspell_new_config

//array pspell_suggest
//bool pspell_check

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
	}

	/**
	 * @expectedException \InvalidArgumentException
	 * @expectedExceptionMessage PSpell dictionary not found for lang: de
	 */
	public function testConstructorWithUnsupportedLang()
	{
		new PSpell(array('lang' => 'de'));
	}

}
