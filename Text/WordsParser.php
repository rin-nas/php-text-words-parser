<?php
/**
 * Parse html text into sentences and words
 * Грамматический разбор html текста на предложения и слова
 *
 * Purpose
 *   * Анализ слов в тесте для реализации каких-либо алгоритмов (например, похожести текстов)
 *   * Использование индексатором для полнотекстового поиска,
 *     отображение фрагментов текста и подсветка найденных слов в результатах поиска
 *
 * Features
 *   * Получение всех слов в тексте в порядке их следования
 *   * Получение всех предложений и слов в тексте в порядке их следования
 *   * Получение уникальных слов в тексте с весами их появления в тексте
 *   * Нормализация текста (описание см. ниже)
 *   * Распределение абсолютных позиций слов к абсолютным байтовым позициям в нормализованном тексте
 *   * Поддержка нескольких языков одновременно
 *   * Работает с любыми языками мира, используемая кодировка — UTF-8.
 *
 * Terminology
 *   Нормализованный текст       — текст с сохранением регистра, с параграфами и переносами строк,
 *                                 но без html тэгов и сущностей, без знака табуляции, ударения, мягкого переноса строк
 *   Слово                       — последовательность букв или цифр (мешанина не допускается!)
 *   Абсолютная позиция слова    — порядковый номер слова в нормализованном тексте
 *   Относительная позиция слова — порядковый номер слова относительно предложения в нормализованном тексте
 *   Байтовая позиция слова      — смещение слова в байтах в нормализованном тексте
 *
 * Example
 *   $wp = new Text_WordsParser(array('Latin', 'Cyrillic'));
 *   $html = file_get_contents('test.html');
 *   $text = $wp->parse($html, $words, $sentences, $uniques, $offset_map);
 *   var_dump($text, $words, $sentences, $uniques, $offset_map);
 *
 * Useful links
 *   http://www.evertype.com/alphabets/index.html    The Alphabets of Europe
 *   http://ru.wikipedia.org/wiki/TF-IDF             Оценка важности слова в контексте текста
 *   http://morpher.ru/Description.aspx              Технология автоматического склонения
 *   http://phpmorphy.sourceforge.net/dokuwiki/demo  Библиотека морфологического анализа на PHP,
 *                                                   демонстрация работы phpMorphy (введи, например, слово "родной" или "раздела")
 *   http://packages.python.org/pymorphy/            Морфологический анализатор
 *
 * History
 *    The class started to be developed in april 2005
 *
 * @link     http://code.google.com/p/php-text-words-parser/
 * @license  http://creativecommons.org/licenses/by-sa/3.0/
 * @author   Nasibullin Rinat
 * @version  5.1.0
 */
class Text_WordsParser
{
	private $dot_reductions = array();
	private $re_langs       = array();

	public function __construct(
		$langs = array(
			#Unicode character properties, from http://pcre.org/pcre.txt
			'Arabic', 'Armenian', 'Balinese', 'Bengali', 'Bopomofo', 'Braille', 'Buginese',
			'Buhid', 'Canadian_Aboriginal', 'Cherokee', /*'Common',*/ ' Coptic', ' Cuneiform',
			'Cypriot', 'Cyrillic', 'Deseret', 'Devanagari', 'Ethiopic', 'Georgian', 'Glagolitic',
			'Gothic', 'Greek', 'Gujarati', 'Gurmukhi', 'Han', 'Hangul', 'Hanunoo', 'Hebrew', 'Hiragana',
			/*'Inherited',*/ 'Kannada', 'Katakana', 'Kharoshthi', 'Khmer', 'Lao', 'Latin',
			'Limbu', 'Linear_B', 'Malayalam', 'Mongolian', 'Myanmar', 'New_Tai_Lue', 'Nko',
			'Ogham', 'Old_Italic', 'Old_Persian', 'Oriya', 'Osmanya', 'Phags_Pa', 'Phoenician',
			'Runic', 'Shavian', 'Sinhala', 'Syloti_Nagri', 'Syriac', 'Tagalog', 'Tagbanwa',
			'Tai_Le', 'Tamil', 'Telugu', 'Thaana', 'Thai', 'Tibetan', 'Tifinagh', 'Ugaritic', 'Yi',
		))
	{
		$this->dot_reductions = include self::_filename('dot-reductions');
		$this->re_langs = self::_re_langs($langs);
	}

	/**
	 * Грамматический разбор html кода на предложения и слова
	 *
	 * @param   string       $s           Html текст
	 * @param   array|null   $words       Массив всех слов:
	 *                                    array(<абсол._поз._слова> => <слово>, ...)
	 * @param   array|null   $sentences   Массив предложений:
	 *                                    array(
	 *                                        <номер_предложения> => array(
	 *                                            <абсол._поз._слова> => <слово>,
	 *                                            ...
	 *                                        ),
	 *                                        ...
	 *                                    )
	 *                                    Внимание! Значение передаётся по ссылке:
	 *                                    $sentences[$sentence_pos][$abs_pos] =& $words[$abs_pos];
	 * @param   array|null   $uniques     Массив уникальных слов, отсортированный по ключам.
	 *                                    В ключах слова в нижнем регистре, в значениях кол-во их появлений в тексте.
	 * @param   array|null   $offset_map  Распределение абс. позиций слов к абс. байтовым позициям в нормализованном тексте:
	 *                                    array(<абсол._поз._слова> => <байт._поз._слова>, ...)
	 * @return  string                    Нормализованный текст
	 */
	public function parse($s, array &$words = null,
							  array &$sentences = null,
							  array &$uniques = null,
							  array &$offset_map = null)
	{
		$s = $this->normalize($s);

		/*
        Розенталь:
          "(?)" ставится после слова для выражения сомнения или недоумения
          "(!)" ставится после слова для выражения автора к чужому тексту (согласия, одобрения или иронии, возмущения)
		*/
		preg_match_all('~(?>#1 letters
							(	#\p{L}++
								(?>' . $this->re_langs . ')
								#special
								(?>		\#     (?!\p{L}|\d)		#programming languages: C#
									|	\+\+?+ (?!\p{L}|\d)		#programming languages: C++, T++, B+ trees, Европа+; but not C+E, C+5
								)?+
							)

							#2 numbers
							|	(	\d++            #digits
									(?> % (?!\p{L}|\d) )?+	#brand names: 120%
								)
							#|	\p{Nd}++  #decimal number
							#|	\p{Nl}++  #letter number
							#|	\p{No}++  #other number

							#paragraph (see self::normalize())
							|	\r\r

							#sentence end by dot
							|	\. (?=[\x20'
										. "\xc2\xa0"   #U+00A0 [ ] no-break space = non-breaking space
										. '] (?!\p{Ll})  #following symbol not letter in lowercase
									)

							#sentence end by other
							|	(?<!\()    #previous symbol not bracket
								[!?;…]++  #sentence end
								#following symbol not
								(?!["\)'
									. "\xc2\xbb"       #U+00BB [»] right-pointing double angle quotation mark = right pointing guillemet
									. "\xe2\x80\x9d"   #U+201D [”] right double quotation mark
									. "\xe2\x80\x99"   #U+2019 [’] right single quotation mark (and apostrophe!)
									. "\xe2\x80\x9c"   #U+201C [“] left double quotation mark
									. ']
								)
						)
						~sxuSX', $s, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
		#cleanup
		$words = array();
		$sentences = array();
		$uniques = array();
		$offset_map = array();

		#init
		$sentence_pos = 0;  #номер предложения
		$abs_pos = 0;  #номер абсолютной позиции слова в тексте
		$w_prev = false;  #предыдущее слово

		foreach ($m as $i => $a)
		{
			$is_alpha = $is_digit = false;
			if ($is_digit = array_key_exists(2, $a)) list($w, $pos) = $a[2];
			elseif ($is_alpha = array_key_exists(1, $a)) list($w, $pos) = $a[1];
			else #delimiter found
			{
				list($w, $pos) = $a[0];
				if ($w !== '.')
				{
					if (! empty($sentences[$sentence_pos]))
					{
						$w_prev = false;
						$sentence_pos++;
					}
					continue;
				}
				if (! empty($sentences[$sentence_pos]))
				{
					$tmp = $w_prev;
					$w_prev = false;
					if ($tmp === false
						#корректно обрабатываем инициалы "И. И. Иванов" и числа "2 + 2 = 4."
						|| (UTF8::strlen($tmp) < 2 && ! ctype_digit($tmp))
						#при обработке вхождений типа "ул. Строителей" нужно смотреть на сокращения, записанные через точку
						|| (is_array($this->dot_reductions) && array_key_exists(UTF8::lowercase($tmp), $this->dot_reductions))) continue;
					$sentence_pos++;
				}
				continue;
			}
			$w_prev = $w;
			$words[$abs_pos] = $w;
			$sentences[$sentence_pos][$abs_pos] =& $words[$abs_pos];
			$offset_map[$abs_pos] = $pos;
			$abs_pos++;
		}
		$uniques = array_count_values(explode(PHP_EOL, UTF8::lowercase(implode(PHP_EOL, $words))));
		ksort($uniques, SORT_REGULAR);
		#d($words, $sentences, $uniques, $offset_map);
		return $s;
	}

	/**
	 * Для массива уникальных слов пересчитывает кол-во появлений слов
	 * в целочисленные веса относительно самого большого веса
	 *
	 * @param   array   $uniques Массив уникальных слов
	 * @param   int     $base    База для вычисления весов (самый большой вес)
	 * @return  array            Массив уникальных слов:
	 *                           array(<слово> => <вес_слова_в_тексте>, ...)
	 */
	public function weights(array $uniques, $base = 65535)
	{
		$total = array_sum($uniques);
		#переводим в целочисленную арифметику
		foreach ($uniques as $k => &$v) $v = round(($v / $total) * $base);
		return $uniques;
	}

	/**
	 * Нормализация html-текста
	 *
	 * @param   string  $s
	 * @return  string
	 */
	public function normalize($s)
	{
		#1. вырезаем html-тэги и форматируем текст как text/plain
		$s = HTML::strip_tags($s, null, true, array('noindex', 'script', 'noscript', 'style', 'map', 'iframe', 'frameset', 'object', 'applet', 'comment', 'button', 'textarea', 'select'));

		#2. замена всех html сущностей (в т.ч. &lt; &gt; &amp; &quot;) в UTF-8
		$s = HTML::entity_decode($s, $is_htmlspecialchars = true);

		#3. вырезаем и заменяем некоторые символы
		$trans = array(
			"\xc2\xad" => '',   #вырезаем "мягкие" переносы строк (&shy;)
			"\t" => ' ',        #\t -- табуляция (\x09)
			"\f" => "\r\n\r\n", #\f -- разрыв страницы (\x0C)
		);
		$s = strtr($s, $trans);
		$s = UTF8::diactrical_remove($s); #remove combining diactrical marks

		#4. заменяем параграфы на перенос строки, отступ слева ("красная" строка) поддерживается
		return preg_replace('~(\r\n|[\r\n])(?:\x20|\\1)+~sSX', "\r\r", $s);
	}

	/**
	 * TODO
	 * Сбор заголовков и ссылок из HTML кода в ассоц. массив вида:
	 * array(
	 *     'title'  => <title>                       #первое вхождение заголовка страницы
	 *     'h[1-5]' => array(<header>, ...)          #уникальный массив подзаголовков
	 *     'links'  => array(<URL> => <title>, ...)  #уникальный массив ссылок на др. html страницы, изображения, флэш и др.
	 * )
	 *
	 * @param   string  $s
	 * @return  array
	 */
	public function structure($s)
	{
		#see HTML::normalize_links()
	}

	private static function _filename($type)
	{
		$a = explode('_', __CLASS__);
		$name = end($a);
		return __DIR__ . '/' . $name . '.' . $type . '.php';
	}

	private static function _re_langs(array $langs)
	{
		$a = array();
		foreach ($langs as $lang) $a[] = '\p{' . preg_quote($lang, '~') . '}++';
		return implode($a, ' | ');
	}

}