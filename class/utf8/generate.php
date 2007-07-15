<?php /*********************************************************************
 *
 *   Copyright : (C) 2006 Nicolas Grekas. All rights reserved.
 *   Email     : nicolas.grekas+patchwork@espci.org
 *   License   : http://www.gnu.org/licenses/lgpl.txt GNU/LGPL, see LGPL
 *
 *   This library is free software; you can redistribute it and/or
 *   modify it under the terms of the GNU Lesser General Public
 *   License as published by the Free Software Foundation; either
 *   version 3 of the License, or (at your option) any later version.
 *
 ***************************************************************************/


class
{
	static

		$utf8Data,
		$DerivedNormalizationProps = 'http://www.unicode.org/Public/UNIDATA/DerivedNormalizationProps.txt',
		$UnicodeData               = 'http://www.unicode.org/Public/UNIDATA/UnicodeData.txt',
		$CompositionExclusions     = 'http://www.unicode.org/Public/UNIDATA/CompositionExclusions.txt';


	static function __static_construct()
	{
		set_time_limit(0);

		self::$utf8Data = resolvePath('data/utf8/');
//		self::$DerivedNormalizationProps = resolvePath('data/utf8/DerivedNormalizationProps.txt');
//		self::$UnicodeData               = resolvePath('data/utf8/UnicodeData.txt');
//		self::$CompositionExclusions     = resolvePath('data/utf8/CompositionExclusions.txt');
	}


	// Generate regular expression from unicode database
	// to check if an UTF-8 string needs normalization
	// $type = NFC | NFD | NFKC | NFKD

	static function quickCheck($type)
	{
		$rx = '';

		$h = fopen(self::$DerivedNormalizationProps, 'rt');
		while (false !== $m = fgets($h))
		{
			if (preg_match('/^([0-9A-F]+(?:\.\.[0-9A-F]+)?)\s*;\s*' . $type . '_QC\s*;\s*[MN]/', $m, $m))
			{
				$m = explode('..', $m[1]);
				$rx .= '\x{' . $m[0] . '}' . (isset($m[1]) ? (hexdec($m[0])+1 == hexdec($m[1]) ? '' : '-') . '\x{' . $m[1] . '}' : '');
			}
		}

		fclose($h);

		$rx = self::optimizeRx($rx . self::combiningCheck());

		return $rx;
	}


	// Generate regular expression from unicode database
	// to check if an UTF-8 string contains combining chars

	static function combiningCheck()
	{
		$rx = '';

		$lastChr = '';
		$lastOrd = 0;
		$interval = 0;

		$h = fopen(self::$UnicodeData, 'rt');
		while (false !== $m = fgets($h))
		{
			if (preg_match('/^([0-9A-F]+);[^;]*;[^;]*;([1-9]\d*)/', $m, $m))
			{
				$rx .= '\x{' . $m[1] . '}';
			}
		}

		fclose($h);

		$rx = self::optimizeRx($rx);

		return $rx;
	}


	// Write the 4+1 above regular expressions to disk

	static function quickChecks()
	{
		$a = 'Generated by utf8_generate::quickChecks()'
			. "\n/(?:.?[" . self::quickCheck('NFC' ) . ']+)+/u'
			. "\n/(?:.?[" . self::quickCheck('NFKC') . ']+)+/u'
			. "\n/[" . self::quickCheck('NFD' ) . ']+/u'
			. "\n/[" . self::quickCheck('NFKD') . ']+/u'
			. "\n" . self::combiningCheck();

		$a = preg_replace("'\\\\x\\{([0-9A-Fa-f]+)\\}'e", 'u::chr(hexdec("$1"))', $a);

		file_put_contents(self::$utf8Data . 'quickChecks.txt', $a);
	}


	// Write unicode data maps to disk

	static function unicodeMaps()
	{
		$upperCase = array();
		$lowerCase = array();
		$combiningClass = array();
		$canonicalComposition = array();
		$canonicalDecomposition = array();
		$compatibilityDecomposition = array();


		$exclusion = array();

		$h = fopen(self::$CompositionExclusions, 'rt');
		while (false !== $m = fgets($h))
		{
			if (preg_match('/^(?:# )?([0-9A-F]+) /', $m, $m))
			{
				$exclusion[u::chr(hexdec($m[1]))] = 1;
			}
		}

		fclose($h);


		$h = fopen(self::$UnicodeData, 'rt');
		while (false !== $m = fgets($h))
		{
			$m = explode(';', $m);

			$k = u::chr(hexdec($m[0]));
			$combClass = (int) $m[3];
			$decomp = $m[5];

			$m[12] && $m[12]!=$m[0] && $upperCase[$k] = self::chr(hexdec($m[12]));
			$m[13] && $m[13]!=$m[0] && $lowerCase[$k] = self::chr(hexdec($m[13]));

			$combClass && $combiningClass[$k] = $combClass;

			if ($decomp)
			{
				$canonic = '<' != $decomp[0];
				$canonic || $decomp = preg_replace("'^<.*> '", '', $decomp);

				$decomp = explode(' ', $decomp);

				$exclude = count($decomp) == 1 || isset($exclusion[$k]);

				$decomp = array_map('hexdec', $decomp);
				$decomp = array_map(array('u','chr'), $decomp);
				$decomp = implode('', $decomp);

				if ($canonic)
				{
					$canonicalDecomposition[$k] = $decomp;
					$exclude || $canonicalComposition[$decomp] = $k;
				}
				else $compatibilityDecomposition[$k] = $decomp;
			}
		}

		fclose($h);

		do
		{
			$m = 0;

			foreach($canonicalDecomposition as $k => $decomp)
			{
				$h = strtr($decomp, $canonicalDecomposition);
				if ($h != $decomp)
				{
					$canonicalDecomposition[$k] = $h;
					$m = 1;
				}

				!isset($canonicalComposition[$decomp])
					&& !isset($exclusion[$k])
					&& 1 < strlen(utf8_decode($decomp))
					&& $canonicalComposition[$decomp] = $k;
			}
		}
		while ($m);

		do
		{
			$m = 0;

			foreach($compatibilityDecomposition as $k => $decomp)
			{
				$h = strtr($decomp, $canonicalDecomposition);
				$h = strtr($h, $compatibilityDecomposition);
				if ($h != $decomp)
				{
					$compatibilityDecomposition[$k] = $h;
					$m = 1;
				}
			}
		}
		while ($m);

		uksort($canonicalComposition, array(__CLASS__, 'cmpByLength'));

		$upperCase                  = serialize($upperCase);
		$lowerCase                  = serialize($lowerCase);
		$combiningClass             = serialize($combiningClass);
		$canonicalComposition       = serialize($canonicalComposition);
		$canonicalDecomposition     = serialize($canonicalDecomposition);
		$compatibilityDecomposition = serialize($compatibilityDecomposition);

		file_put_contents(self::$utf8Data . 'upperCase.ser'                 , $upperCase);
		file_put_contents(self::$utf8Data . 'lowerCase.ser'                 , $lowerCase);
		file_put_contents(self::$utf8Data . 'combiningClass.ser'            , $combiningClass);
		file_put_contents(self::$utf8Data . 'canonicalComposition.ser'      , $canonicalComposition);
		file_put_contents(self::$utf8Data . 'canonicalDecomposition.ser'    , $canonicalDecomposition);
		file_put_contents(self::$utf8Data . 'compatibilityDecomposition.ser', $compatibilityDecomposition);
	}

	static function cmpByLength($a, $b)
	{
		return strlen($b) - strlen($a);
	}

	static function optimizeRx($rx)
	{
		$rx = preg_replace('/\\\\x\\{([0-9A-Fa-f]+)\\}-\\\\x\\{([0-9A-Fa-f]+)\\}/e', '"\x{".implode("}\x{",array_map("dechex",range(0x$1, 0x$2)))."}"', $rx);

		preg_match_all('/[0-9A-Fa-f]+/', $rx, $rx);

		$rx = array_map('hexdec', $rx[0]);
		$rx = array_unique($rx);
		sort($rx);

		$a = '';
		$last = 0;
		$interval = 0;

		foreach ($rx as $rx)
		{
			if ($last+1 == $rx)
			{
				++$last;
				++$interval;
			}
			else
			{
				$interval && $a .= ($interval > 1 ? '-' : '') . '\x{' . dechex($last) . '}';

				$last = $rx;
				$interval = 0;

				$a .= '\x{' . dechex($rx) . '}';
			}
		}

		$interval && $a .= ($interval > 1 ? '-' : '') . '\x{' . dechex($last) . '}';

		return $a;
	}
}
