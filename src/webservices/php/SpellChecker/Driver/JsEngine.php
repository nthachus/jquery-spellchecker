<?php namespace SpellChecker\Driver;

/**
 * PHPSpellCheck driver class
 * (C)2004-Present Jacob Mellor - me@jacobmellor.com - All rights reserved.
 */
class JsEngine extends \SpellChecker\Driver
{
	protected $_default_config = array(
		'dictionaryPath' => '/../../../../../dictionary/jspell/',
		'centralDictionary' => 'custom.txt',
		'lang' => 'en',
		'ignoreAllCaps' => true,
		//'ignoreNumeric' => true,
		'caseSensitive' => false,
		'suggestionTolerance' => 1.5,// 1: the tolerance of the spellchecker to 'unlikely' suggestions. 0=intolerant ... 10=very tolerant
	);

	protected $bannedWords = array();
	protected $enforcedWords = array();
	protected $commonTypos = array();

	private $_dictArray = array();
	private $_metaArray = array();
	private $_posArray = array();
	private $_contextArray = array();

	//private $suggestionsCache = array();
	private $simpleSpellCasedCache = array();
	private $simpleSpellUncasedCache = array();

	public function __construct($config = array())
	{
		$this->_default_config['dictionaryPath'] = __DIR__ . $this->_default_config['dictionaryPath'];
		parent::__construct($config);

		if (!is_dir($this->_config['dictionaryPath'])) {
			throw new \RuntimeException('Dictionary directory not found.');
		}

		// serialize to md5 cache file
		unset($config['lang']);
		$cacheFile = $this->_config['dictionaryPath'] . $this->_config['lang'] . '.' . md5(serialize($config));
		if (file_exists($cacheFile) && ($instance = @unserialize(@file_get_contents($cacheFile)))) {

			foreach (get_object_vars($this) as $prop => $val) {
				if (strpos($prop, '_config') === false)
					$this->{$prop} =& $instance->{$prop};
			}
			unset($instance);

		} else {
			// loads all dictionaries requested by the API
			$this->loadDictionary($this->_config['lang']);

			// add vocabulary to the spellchecker from a text file loaded from the dictionary path
			$this->loadCustomDictionary($this->_config['centralDictionary']);

			// ban a list of words which will never be allowed as correct spellings. This is great for filtering profanity.
			$this->loadCustomBannedWords($this->getConfig('bannedWordsFile', 'rules/banned-words.txt'));
			// you can also add banned words from an array which you could easily populate from an SQL query
			if (!empty($this->_config['bannedWords']))
				$this->addBannedWords((array)$this->_config['bannedWords']);//veryRudeWord

			// load a lost of Enforced Corrections from a file. This allows you to enforce a spelling suggestion for a specific word or acronym.
			$this->loadEnforcedCorrections($this->getConfig('enforcedCorrectionsFile', 'rules/enforced-corrections.txt'));

			// load a list of common typing mistakes to fine tune the suggestion performance.
			$this->loadCommonTypos($this->getConfig('commonMistakesFile', 'rules/common-mistakes.txt'));

			@file_put_contents($cacheFile, serialize($this));
		}
	}

	protected function getConfig($key, $default = null)
	{
		return array_key_exists($key, $this->_config) ? $this->_config[$key] : $default;
	}

	public function setContext($tokens)
	{
		$this->_contextArray = $tokens;
	}

	//region Default Settings

	protected function loadDictionary($id)
	{
		if (isset($this->_dictArray[$id]) && is_array($this->_dictArray[$id])) {
			return;
		}

		$filePath = $this->_config['dictionaryPath'] . $id . '.dic';
		if (!file_exists($filePath)) {
			throw new \InvalidArgumentException("'$id' dictionary file not found.");
		}

		$strWholeDict = file_get_contents($filePath);
		$this->decipherStrDict($id, $strWholeDict);
	}

	private function decipherStrDict($id, $strWholeDict)
	{
		$this->clearCache();

		if (isset($this->_dictArray[$id]) && is_array($this->_dictArray[$id])) {
			return;
		}

		$this->_dictArray[$id] = array();

		static $delimit = "\n==========================================\n";
		list($words, $phones, $proximity) = explode($delimit, $strWholeDict);

		static $smallDelimit = "\n+++++++++\n";
		$wordsByLetter = explode($smallDelimit, $words);
		foreach ($wordsByLetter as $wordsInLetter) {

			$char = $wordsInLetter[0];
			$dicIndex = static::dicIndex($char);
			$this->_dictArray[$id][$dicIndex] = explode("\n", $wordsInLetter);
		}

		$phonesByLetter = explode($smallDelimit, $phones);
		foreach ($phonesByLetter as $phonesInLetter) {

			$char = $phonesInLetter[0];
			$dicIndex = static::dicIndex($char);
			$eachPhoneInLetter = explode("\n", $phonesInLetter);

			$this->_metaArray[$id][$dicIndex] = array();
			foreach ($eachPhoneInLetter as $strPhone) {
				list($pKey, $pValue) = explode('#', $strPhone);
				$this->_metaArray[$id][$dicIndex][$pKey] = $pValue;
			}
		}

		if ($proximity = trim($proximity)) {
			$this->_posArray[$id] = '$' . $proximity;
		}
	}

	public function clearCache()
	{
		unset($this->simpleSpellUncasedCache, $this->simpleSpellCasedCache/*, $this->suggestionsCache*/);

		$this->simpleSpellUncasedCache = array();
		$this->simpleSpellCasedCache = array();
		//$this->suggestionsCache = array();
	}

	protected function loadCustomDictionary($filePath)
	{
		if (empty($filePath)) {
			return false;
		}

		if (!file_exists($filePath)) {
			$filePath = $this->_config['dictionaryPath'] . $filePath;

			if (!file_exists($filePath)) {
				throw new \InvalidArgumentException('Custom dictionary file not found.');
			}
		}

		$key = 'APP_CUSTOM_' . substr(md5($filePath), 0, 5);
		$strWholeDict = file_get_contents($filePath);
		$out = preg_split('/\s+/u', trim($strWholeDict));

		return $this->buildDictionary($out, $key);
	}

	public function buildDictionary($arrWholeDict, $idInMemory = false)
	{
		if ($idInMemory) {
			$this->clearCache();
		}

		sort($arrWholeDict);
		$arrWholeDict = array_unique($arrWholeDict);

		static $smallDelimit = "+++++++++\n";
		$words = '';
		$oldWord = '#';
		$arrPhones = array();
		$countInLetter = 0;
		$countInTotal = 0;

		foreach ($arrWholeDict as $word) {
			if ($oldWord != '#' && $oldWord != $word[0]) {
				$words .= $smallDelimit;

				if ($idInMemory) {
					if (!isset($this->_dictArray[$idInMemory])) {
						$this->_dictArray[$idInMemory] = array();
					}

					$this->_dictArray[$idInMemory][static::dicIndex($oldWord)]
						= array_slice($arrWholeDict, $countInTotal - $countInLetter, $countInLetter);
				}

				$countInLetter = 0;
			}

			$words .= $word . "\n";

			$p = static::phoneticCode($word);
			$pIndex = static::dicIndex($p);

			if (strlen($word) > 0) {
				$indexCode = $word[0] . $countInLetter;
			} else {
				$indexCode = '' . $countInLetter;
			}

			if (!isset($arrPhones[$pIndex])) {
				$arrPhones[$pIndex] = array();
			}

			if (!isset($arrPhones[$pIndex][$p])) {
				$arrPhones[$pIndex][$p] = $indexCode;
			} else {
				$arrPhones[$pIndex][$p] .= '|' . $indexCode;
			}

			if (strlen($word) > 0) {
				$oldWord = $word[0];
			} else {
				$oldWord = '';
			}

			$countInLetter++;
			$countInTotal++;
		}

		if ($idInMemory) {
			if (!is_array($this->_dictArray[$idInMemory])) {
				$this->_dictArray[$idInMemory] = array();
			}

			$this->_dictArray[$idInMemory][static::dicIndex($oldWord)]
				= array_slice($arrWholeDict, $countInTotal - $countInLetter, $countInLetter);
		}

		ksort($arrPhones);
		$phones = '';

		foreach ($arrPhones as $myKey => &$arrPhonesByIndex) {
			ksort($arrPhonesByIndex);

			if ($phones != '') {
				$phones .= $smallDelimit;
			}

			foreach ($arrPhonesByIndex as $k => $v) {
				$phones .= "$k#$v\n";
			}
		}

		if ($idInMemory) {
			$this->_metaArray[$idInMemory] = $arrPhones;
		}

		static $delimit = "==========================================\n";
		return $words . $delimit . $phones . $delimit;
	}

	protected function loadCustomBannedWords($filePath)
	{
		if (empty($filePath)) {
			return;
		}

		if (!file_exists($filePath)) {
			$filePath = $this->_config['dictionaryPath'] . $filePath;

			if (!file_exists($filePath)) {
				throw new \InvalidArgumentException('Custom banned-words file not found.');
			}
		}

		$strWholeDict = file_get_contents($filePath);
		$out = preg_split('/\s+/u', trim($strWholeDict));

		$this->addBannedWords($out);
	}

	public function addBannedWords($array)
	{
		foreach ($array as $key) {
			$this->bannedWords[strtolower($key)] = false;
		}
	}

	protected function loadEnforcedCorrections($filePath)
	{
		if (empty($filePath)) {
			return;
		}

		if (!file_exists($filePath)) {
			$filePath = $this->_config['dictionaryPath'] . $filePath;

			if (!file_exists($filePath)) {
				throw new \InvalidArgumentException('Enforced corrections file not found.');
			}
		}

		$strWholeDict = file_get_contents($filePath);
		$out = explode("\n", trim($strWholeDict));//preg_split('/\s+/u');

		$this->buildEnforcedCorrections($out);
	}

	protected function buildEnforcedCorrections($array)
	{
		foreach ($array as $line) {
			// USA --> United States Of America || United States Army
			list($key, $lineResults) = explode('-->', $line);

			$key = strtolower(trim($key));
			$lineResults = preg_split('/\s*\|\|\s*/u', trim($lineResults));

			$this->bannedWords[$key] = false;
			$this->enforcedWords[$key] = $lineResults;
		}
	}

	protected function loadCommonTypos($filePath)
	{
		if (empty($filePath)) {
			return;
		}

		if (!file_exists($filePath)) {
			$filePath = $this->_config['dictionaryPath'] . $filePath;

			if (!file_exists($filePath)) {
				throw new \InvalidArgumentException('Common typos file not found.');
			}
		}

		$strWholeDict = file_get_contents($filePath);
		$out = explode("\n", trim($strWholeDict));

		$this->buildCommonTypos($out);
	}

	protected function buildCommonTypos($array)
	{
		foreach ($array as $line) {
			$lineA = explode('-->', $line);
			if (count($lineA) == 2) {

				list($key, $value) = $lineA;
				$this->commonTypos[strtolower(trim($key))] = trim($value);
			}
		}
	}

	//endregion

	//region SpellCheckWord

	protected function is_incorrect_word($word)
	{
		$wordU = strtoupper($word);
		$wordL = strtolower($word);

		if ($this->_config['ignoreAllCaps'] && $wordU === $word && $wordU != $wordL) {
			return false;
		}
		if ($wordU == $wordL) {
			return false;
		}

		$word = static::safeCleanPunctuation($word);

		return (!$this->simpleSpell($word, !$this->_config['caseSensitive'])
			&& !$this->simpleSpell(lcfirst($word), !$this->_config['caseSensitive']));
	}

	private static function safeCleanPunctuation($word)
	{
		return str_replace(
			array('"', '\'', '-', '–', '‘', '~', '`', '’'),
			array('\'', '\'', ' ', ' ', '\'', '-', '\'', '\''),
			$word);
	}

	protected function simpleSpell($word, $ignoreCase = true)
	{
		$wordL = strtolower($word);
		if (isset($this->bannedWords[$wordL])) {
			return false;
		}

		if ($ignoreCase) {
			if (isset($this->simpleSpellUncasedCache[$wordL])) {
				return $this->simpleSpellUncasedCache[$wordL];
			}
		} elseif (isset($this->simpleSpellCasedCache[$word])) {
			return $this->simpleSpellCasedCache[$word];
		}

		$dicIndex = static::dicIndex($word);
		if ($ignoreCase) {
			$dicIndexU = static::dicIndex(strtoupper($word));
			$dicIndexL = static::dicIndex($wordL);
		} else {
			$dicIndexU = $dicIndexL = 0;
		}

		foreach (array_keys($this->_dictArray) as $dictKey) {
			if (!isset($this->_dictArray[$dictKey][$dicIndex])) {
				continue;
			}

			if ($ignoreCase) {
				if (isset($this->_dictArray[$dictKey][$dicIndexL])
					&& static::inArrayIgnoreCase($word, $this->_dictArray[$dictKey][$dicIndexL])
				) {
					$this->simpleSpellUncasedCache[$wordL] = true;
					return true;
				}

				if (isset($this->_dictArray[$dictKey][$dicIndexU])
					&& static::inArrayIgnoreCase($word, $this->_dictArray[$dictKey][$dicIndexU])
				) {
					$this->simpleSpellUncasedCache[$wordL] = true;
					return true;
				}
			} elseif (static::binarySearch($this->_dictArray[$dictKey][$dicIndex], $word, 0, count($this->_dictArray[$dictKey][$dicIndex]))) {
				$this->simpleSpellCasedCache[$word] = true;
				return true;
			}
		}

		if ($ignoreCase) {
			$this->simpleSpellUncasedCache[$wordL] = false;
			$this->simpleSpellCasedCache[$word] = false;
		} else {
			$this->simpleSpellCasedCache[$word] = false;
		}

		return false;
	}

	private static function dicIndex($word)
	{
		return ord(ltrim($word)[0]);
	}

	private static function inArrayIgnoreCase($str, $array)
	{
		if (!is_array($array)) {
			return false;
		}
		return preg_grep('/^' . preg_quote($str, '/') . '$/i', $array);
	}

	private static function binarySearch($array, $key, $low, $high)
	{
		if ($low > $high) { // termination case
			return false;
		}

		// gets the middle of the array
		$middle = intval(($low + $high) / 2);

		if (isset($array[$middle]) && $array[$middle] === $key) { // if the middle is our key
			return true;
		}
		if (isset($array[$middle]) && $key < $array[$middle]) { // our key might be in the left sub-array
			return static::binarySearch($array, $key, $low, $middle - 1);
		}
		// our key might be in the right sub-array
		return static::binarySearch($array, $key, $middle + 1, $high);
	}

	//endregion

	//region Suggestions

	protected function get_word_suggestions($word)
	{
		$wordL = strtolower($word);
		if (isset($this->enforcedWords[$wordL])) {
			return $this->enforcedWords[$wordL];
		}
		//if (isset($this->suggestionsCache[$word]))
		//	return $this->suggestionsCache[$word];

		$cc = $this->correctCase($word, false);
		if ($cc) {
			return array($cc);
		}

		$M = $this->arrMetaPhones($word);
		$N = $this->arrNearMiss($word);

		$all = array_merge($M, $N);
		$all = array_unique($all);
		$all = $this->distanceSort($word, $all);

		if (isset($this->commonTypos[$wordL])) {
			array_unshift($all, $this->commonTypos[$wordL]);
			$all = array_unique($all);
		}

		//$this->suggestionsCache[$word] = $all;
		return $all;
	}

	protected function correctCase($word, $startOfSentence = false)
	{
		$dicIndexU = static::dicIndex(strtoupper($word));
		$dicIndexL = static::dicIndex(strtolower($word));

		$out = '';
		foreach ($this->listLiveDictionaries() as $dictKey) {
			if (isset($this->_dictArray[$dictKey][$dicIndexL])) {
				$r = static::inArrayIgnoreCase($word, $this->_dictArray[$dictKey][$dicIndexL]);
			}
			if (!empty($r)) {
				$out = array_values($r)[0];
				break;
			}

			if (isset($this->_dictArray[$dictKey][$dicIndexU])) {
				$r = static::inArrayIgnoreCase($word, $this->_dictArray[$dictKey][$dicIndexU]);
			}
			if (!empty($r)) {
				$out = array_values($r)[0];
				break;
			}
		}

		if ($out && $startOfSentence) {
			$out = ucfirst($out);
		}
		return $out;
	}

	private function arrMetaPhones($word)
	{
		$p = static::phoneticCode($word);
		$pIndex = static::dicIndex($p);

		$results = array();
		foreach ($this->listLiveDictionaries() as $dictKey) {
			if (isset($this->_metaArray[$dictKey][$pIndex]) && isset($this->_metaArray[$dictKey][$pIndex][$p])) {

				$lookups = explode('|', $this->_metaArray[$dictKey][$pIndex][$p]);
				foreach ($lookups as $lookup) {
					$lookupCode = static::dicIndex($lookup[0]);
					$lookIndex = (int)substr($lookup, 1);

					$results[] = $this->_dictArray[$dictKey][$lookupCode][$lookIndex];
				}
			}
		}

		sort($results);
		return array_unique($results);
	}

	protected static function phoneticCode($word)
	{
		$word = static::cleanForeign($word);
		$p = substr(metaphone($word), 0, 4);

		if (empty($p)) {
			return '0';
		}

		if ($p[0] == 'E' || $p[0] == 'I' || $p[0] == 'O' || $p[0] == 'U') {
			$p[0] = 'A';
		}

		return $p;
	}

	private static function cleanForeign($word)
	{
		return str_replace(
			array('Ÿ', 'Ý', 'ý', 'ÿ', 'À', 'Á', 'Â', 'Ã', 'Ä', 'Å', 'à', 'á', 'â', 'ã', 'ä', 'å', 'È', 'É', 'Ê', 'Ë', 'è', 'é', 'ê', 'ë', 'Ì', 'Í', 'Î', 'Ï', 'ì', 'í', 'î', 'ï', 'Ò', 'Ó', 'Ô', 'Õ', 'Ö', 'ó', 'ô', 'õ', 'ö', 'ø', 'Ù', 'Ú', 'Û', 'Ü', 'ù', 'ú', 'û', 'ü', 'Œ', 'œ', 'Æ', 'æ', 'ß', 'Š', 'š', 'Ž', 'Ñ', 'ñ', 'ð', 'Ð', 'Þ', 'þ'),
			array('Y', 'Y', 'y', 'y', 'A', 'A', 'A', 'A', 'A', 'A', 'a', 'a', 'a', 'a', 'a', 'a', 'E', 'E', 'E', 'E', 'e', 'e', 'e', 'e', 'I', 'I', 'I', 'I', 'i', 'i', 'i', 'i', '', 'O', 'O', 'O', 'O', 'O', 'o', 'o', 'o', 'o', 'o', 'U', 'U', 'U', 'U', 'u', 'u', 'u', 'u', 'OE', 'oe', 'AE', 'ae', 'ss', 'SH', 'sh', 'S', 'N', 'n', 'th', 'th', 'TH', 'TH'),
			$word);
	}

	private function arrNearMiss($w)
	{
		//$w = strtolower($w);
		$results = array();
		$strTry = 'abcdefghijklmnopqrstuvwxyz \'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

		if ($w != lcfirst($w)) {
			$words = array($w, lcfirst($w));
		} else {
			$words = array('' . $w);
		}

		foreach ($words as $word) {
			for ($l = 0, $k = strlen($word); $l < $k; $l++) {

				for ($i = 0, $n = strlen($strTry); $i < $n; $i++) {
					$letter = $strTry[$i];

					if ($l == 0 || $i < 28) {
						$guess = $word;
						$guess[$l] = $letter;
						$results[] = trim($guess);

						$guess = substr($word, 0, $l) . $letter . substr($word, $l);
						$results[] = trim($guess);

						if ($letter == ' ' && $l > 0) {
							for ($m = $l + 2; $m < $k - 1; $m++) {
								$results[] = trim(substr($guess, 0, $m) . ' ' . substr($guess, $m));
							}
						}
					}

					if ($l == 0 && $letter !== ' ' && $letter !== '\'') {
						$guess = $word;
						$guess = $letter . $guess;
						$results[] = trim($guess);
					}
				}

				if ($l > 0) {
					$guess = $word;
					$guess2 = $guess;
					$guess[$l] = $guess2[$l - 1];
					$guess[$l - 1] = $guess2[$l];
					$results[] = trim($guess);
				}

				// swap
				$guess = $word;
				$guess[$l] = '^';
				$results[] = trim(str_replace('^', '', $guess));
				// delete
			}
		}

		sort($results);
		$results = array_unique($results);

		$output = array();
		foreach ($results as $result) {
			if ($this->spellCheckAndSpaces($result, false)) {
				$output[] = $result;
			}
		}

		return $output;
	}

	protected function spellCheckAndSpaces($string, $ignoreCase = false)
	{
		if (strpos($string, ' ') === false) {
			return $this->simpleSpell($string, $ignoreCase);
		}

		$arrWords = str_word_count($string, 1, '¿¡¬√ƒ≈‡·‚„‰Â»… ÀËÈÍÎ“”‘’÷ÛÙıˆ¯Ÿ⁄€‹˘˙˚¸ü›˝ˇå;ú;∆Êﬂäöö—Ò–ﬁ˛ˇ\'');
		foreach ($arrWords as $word) {
			if (empty($word)) {
				return false;
			}

			if (!$this->simpleSpell($word, $ignoreCase)) {
				return false;
			}
		}

		return true;
	}

	private function distanceSort($word, $suggestions)
	{
		if (empty($suggestions)) {
			return $suggestions;
		}
		sort($suggestions);

		$dictionaries = $this->listLiveDictionaries();
		$disArray = array();

		foreach ($suggestions as $suggestion) {
			if (isset($this->bannedWords[strtolower($suggestion)])) {
				continue;
			}

			$distance = levenshtein($word, $suggestion);
			if ($distance < 5) {

				$distance = static::pseudoLevenshtein($word, $suggestion);
				if ($distance < 3.5) {

					foreach ($dictionaries as $dictName) {
						if (empty($this->_posArray[$dictName])) {
							continue;
						}

						$posStr = &$this->_posArray[$dictName];
						$p = 0;
						if (strpos($posStr, $suggestion) !== false) {
							$p = strpos($posStr, strtoupper('$' . $suggestion . '$'));
						}

						if ($p !== false) {//> 0
							// POSITION
							if (strpos($posStr, '$+=') === 0) {
								$distance -= 0.3;
							} elseif ($p) {
								$l = strlen($posStr);
								$r = pow((($l - $p) / $l), 2) * 0.6;
								$distance -= $r;
							}
						}
					}

					if (count($this->_contextArray) > 0 && in_array($suggestion, $this->_contextArray, true)) {

						$cCount = count(array_keys($this->_contextArray, $suggestion, true));
						if ($cCount) {
							$distance -= (pow($cCount, 0.4) * .75);
						}
					}
				}
			}

			$disArray[$suggestion/* . '-' . $distance */] = $distance;
		}
		asort($disArray);

		$disArrayKeys = array_keys($disArray);
		$min = max(0.4, $disArray[$disArrayKeys[0]]);

		$maxVariance = sqrt($min) + $this->_config['suggestionTolerance'] + 0.5;
		$maxRes = sqrt(strlen($word) - 1) + $this->_config['suggestionTolerance'];

		for ($i = 0; $i < count($disArray); $i++) {
			if ($disArray[$disArrayKeys[$i]] > $maxVariance || $i > $maxRes) {
				$disArrayKeys = array_slice($disArrayKeys, 0, $i);

				$i = 1000000;
			}
		}

		return $disArrayKeys;
	}

	protected function listLiveDictionaries()
	{
		return array_keys($this->_dictArray);
	}

	protected static function pseudoLevenshtein($word, $try)
	{
		$caseMod = ((strtolower($word[0]) == $word[0]) != (strtolower($try[0]) == $try[0]));

		$w = strtolower($word);
		$t = strtolower($try);
		if ($w == $t) {
			return 0.5;
		}

		$w = static::cleanPunctuation($w);
		$t = static::cleanPunctuation($t);
		if ($w == $t) {
			return 0.4;
		}

		$w = static::cleanForeign($w);
		$t = static::cleanForeign($t);
		if ($w == $t) {
			return 1;
		}

		$wNoVowel = static::stripVowels($w);
		$tNoVowel = static::stripVowels($t);

		$d = levenshtein($w, $t);
		if ($wNoVowel == $tNoVowel) {
			$d -= 2;
		}

		if ($w[0] != $t[0]) {
			$d += 0.5;
		}
		if ($w[strlen($w) - 1] == $t[strlen($t) - 1]) {
			$d -= 0.3;
		}

		if (strlen($w) > 2 && strtolower($w[strlen($w) - 1]) === 's' && strtolower($t[strlen($t) - 1]) === 's') {
			$d -= 0.4;
		}

		if ($caseMod) {
			$d++;

			$len = strlen($t);
			if ($len > 3) {
				$d += 0.15;
			}
			if ($len > 4) {
				$d += 0.25;
			}
			if ($len > 5) {
				$d += 0.25;
			}
			if ($len > 6) {
				$d += 0.1;
			}
		}

		if (strpos($t, ' ') !== false) {
			$spaceTest = explode(' ', $t);
			$d += count($spaceTest) - 1;

			foreach ($spaceTest as $frag) {
				if (static::stripVowels($frag) == $frag) {
					$d += 1.5;
					if (strlen($frag) == 1) {
						$d += 1;
					}
				}

				if (strlen($frag) == 1) {
					$d += 1.5;
				}
				if (strlen($frag) < 2) {
					$d += 1;
				}
				if (strlen($frag) < 3) {
					$d += 0.6;
				}
				if (strlen($frag) < 4) {
					$d += 0.4;
				}
			}
		}

		$w = str_replace(' ', '', $w);
		$t = str_replace(' ', '', $t);

		$wArray = str_split($w);
		$tArray = str_split($t);
		sort($wArray);
		sort($tArray);

		if (implode('', $wArray) == implode('', $tArray)) {
			$d += -.9;
		}

		$wArray = array_unique($wArray);
		$tArray = array_unique($tArray);

		if (implode('', $wArray) == implode('', $tArray)) {
			$d += -0.9;
		}

		//removeDoubleChars
		$w = str_replace('ie', 'y', $w);
		$t = str_replace('ie', 'y', $t);
		$w = str_replace('z', 's', $w);
		$t = str_replace('z', 's', $t);
		$w = str_replace('ph', 'f', $w);
		$t = str_replace('ph', 'f', $t);

		if ($w == $t) {
			$d -= 1;
		}
		if ($t == $tNoVowel) {
			$d += 0.4;
		}
		if ($wNoVowel == $tNoVowel) {
			$d -= -0.8;
		}

		return $d;
	}

	private static function cleanPunctuation($word)
	{
		return str_replace(array('"', '\'', '-', '–', '‘', '~', '`', '’'), '', $word);
	}

	private static function stripVowels($word)
	{
		return str_replace(array('a', 'e', 'i', 'o', 'u', 'A', 'E', 'I', 'O', 'U'), '', $word);
	}

	//endregion
}
