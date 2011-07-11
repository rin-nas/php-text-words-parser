<?php
/**
 * Класс для обработки HTML
 *
 * @created  2009-12-02
 * @license  http://creativecommons.org/licenses/by-sa/3.0/
 * @author   Nasibullin Rinat: http://orangetie.ru/, http://rin-nas.moikrug.ru/
 * @charset  UTF-8
 * @version  2.0.11
 */
class HTML
{
	/**
	 * Regular expression for tag attributes.
	 * Correct parse dirty and broken HTML in a singlebyte or multibyte UTF-8 charset!
	 *
	 * В библиотеке PCRE для PHP \s - это любой пробельный символ, а именно класс символов [\x09\x0a\x0c\x0d\x20\xa0] или, по другому, [\t\n\f\r \xa0]
	 * Если \s используется с модификатором /u, то \s трактуется как [\x09\x0a\x0c\x0d\x20]
	 *
	 * @var string
	 */
	public static $re_attrs = '(?![a-zA-Z\d])  #statement, which follows after a tag
                               #correct attributes
                               (?>
                                   [^>"\'`]++
                                 | (?<=[\=\x00-\x20\x7f]|\xc2\xa0) "[^"]*+"
                                 | (?<=[\=\x00-\x20\x7f]|\xc2\xa0) \'[^\']*+\'
                                 | (?<=[\=\x00-\x20\x7f]|\xc2\xa0) `[^`]*+`
                               )*+
                               #incorrect attributes
                               [^>]*+';

	/**
	 * Допустимые тэги и атрибуты, которым присваивается URL
	 * Служебные атрибуты: alt, title, target, rel
	 * @var array
	 */
	public static $url_tags = array(
		'body'   => 'background',
		'table'  => 'background',
		'tr'     => 'background',
		'td'     => 'background',
		'img'    => 'src|longdesc|alt',
		'frame'  => 'src|longdesc',
		'iframe' => 'src|longdesc',
		'input'  => 'src|alt',
		'script' => 'src',
		'a'      => 'href|title|rel|target',
		'area'   => 'href|alt|rel|target',
		'link'   => 'href|title|rel',
		'base'   => 'href',
		'object' => 'classid|codebase|data|usemap',
		'applet' => 'codebase|alt',  #HTML-4.01 deprecated tag
		'form'   => 'action',
		'q'      => 'cite',
		'ins'    => 'cite',
		'del'    => 'cite',
		'blockquote' => 'cite',
		'head'   => 'profile',
	);

	/**
	 * @var array
	 */
	private static $_safe_tags;

	/**
	 * @var array
	 */
	private static $_safe_attrs;

	/**
	 * @var array
	 */
	private static $_safe_attr_links;

	/**
	 * @var array
	 */
	private static $_safe_protocols;


	/**
	 * @var array
	 */
	private static $_normalize_links;

	/**
	 * @var array
	 */
	private static $_subdomains_map = array();

	#запрещаем создание экземпляра класса, вызов методов этого класса только статически!
	private function __construct() {}

	/**
	 *
	 * @param   array|null   $subdomains_map
	 *                       Пример:
	 *                       array(
	 *                           'static'   => 'http://yandex.st',
	 *                           'resize'   => 'http://resize.yandex.ru',
	 *                           'feedback' => 'http://feedback.yandex.ru',
	 *                       )
	 */
	public static function init(array $subdomains_map = null)
	{
		if ($subdomains_map) self::$_subdomains_map = $subdomains_map;
	}

	/**
	 * Возвращает строку с HTML кодом атрибутов для использования в тэге.
	 * Все названия атрибутов преобразуются в нижний регистр.
	 *
	 * @param   array|null   $attrs                Массив атрибутов тэгов
	 * @param   string       $delim                Для значения атрибута в виде массива используется
	 *                                             разделитель, через который будет склейка массива в строку
	 * @param   bool         $is_null_value_check  Если значение одного из атрибутов равно NULL, возвращается NULL
	 * @return  string|bool|null                   Returns FALSE if error occured
	 */
	public static function attrs($attrs, $delim = ', ', $is_null_value_check = false)
	{
		if (! ReflectionTypeHint::isValid()) return false;
		if (is_null($attrs)) return null;

		$a = array();
		foreach ($attrs as $attr => $value)
		{
			if ($is_null_value_check && is_null($value)) return null;
			$attr = strtolower($attr);
			if (is_bool($value))
			{
				if ($value) $a[] = $attr . '="' . htmlspecialchars($attr) . '"';
				continue;
			}
			if (is_array($value))
			{
				if ($attr === 'class') $delim = ' ';
				elseif ($attr === 'style' || preg_match('/^on[a-z]+/siSX', $attr)) $delim = ';';
				elseif (array_key_exists($attr, self::_url_attrs())
					&& strpos('alt|title|target|rel', $attr) === false) $value = URL::build($value);
				if (is_array($value)) $value = implode($delim, $value);
			}
			if (is_scalar($value)) $a[] = $attr . '="' . htmlspecialchars($value) . '"';
		}
		return implode(' ', $a);
	}

	/**
	 * Возвращает строку с HTML кодом непарного тэга.
	 *
	 * @param   string            $name                 Название тэга
	 * @param   array|null        $attrs                Массив атрибутов тэгов
	 * @param   bool              $is_null_value_check  Если значение одного из атрибутов равно NULL, то тэг не выводится
	 * @return  string|bool|null                        Returns FALSE if error occured
	 */
	public function tag($name, array $attrs = null, $is_null_value_check = true)
	{
		if (! ReflectionTypeHint::isValid()) return false;
		$attrs = self::attrs($attrs, ', ', $is_null_value_check);
		if (! is_string($attrs)) return $attrs;
		if (strlen($attrs) > 0) $attrs = ' ' . $attrs;
		return '<' . strtolower($name) . $attrs . ' />';
	}

	/**
	 * Возвращает строку с URL для использования в атрибутах тэгов,
	 * например для <a href="...">, <img src="..."> и др.
	 *
	 * @param   string|array|null  $s
	 * @param   bool               $is_html_quote
	 * @return  string|bool|null
	 */
	public static function url($s, $is_html_quote = true)
	{
		if (! ReflectionTypeHint::isValid()) return false;
		if (is_null($s)) return $s;

		if (is_array($s)) $s = URL::build($s);
		if ($s === false) return false;
		return $is_html_quote ? htmlspecialchars($s) : $s;
	}

	/**
	 * Формирование ссылки на файл.
	 * Для файлов, имеющих расширение через точку и не имеющих знака вопроса,
	 * дописывается время модификации в unixtime, например:
	 *   /path/to/file.ext?12345678  (файл найден)
	 *   /path/to/file.ext?0#404     (файл не найден)
	 *
	 * @param   string|null       $filename
	 * @param   bool              $is_html_quote
	 * @return  string|bool|null
	 */
	public static function src($filename, $is_html_quote = true)
	{
		if (! ReflectionTypeHint::isValid()) return false;
		if (is_null($filename)) return $filename;

		$dir = dirname($_SERVER['SCRIPT_FILENAME']);
		$www_dir = substr($dir, strlen($_SERVER['DOCUMENT_ROOT']));

		$query_string = '';
		if (substr($filename, 0, 2) === '//') $s = $www_dir . substr_replace($filename, '/' . $_REQUEST['lang'] . '/', 0, 2);
		#смотрим наличие протокола (ftp://, http://, mysql://, ...)
		elseif (strpos($filename, '://') !== false && preg_match('~^[a-z][-a-z\d_]{2,19}+(?<![-_]):\/\/~sSX', $filename)) $s = $filename;
		elseif (strpos($filename, '?') === false &&
				#смотрим наличие расширения файла
				pathinfo($filename, PATHINFO_EXTENSION) &&
				#смотрим наличие номера версии, например: /ajax/libs/jquery/1.4.2/jquery.min.js
				! preg_match('~\d\.\d~sSX', $filename))
		{
			$mtime = file_exists($dir . $filename) ? @filemtime($dir . $filename) : null;
			$s = $www_dir . $filename . $query_string = '?' . intval($mtime);
			if (! $mtime) $s .= '#404';  #специальная сигнатура '?0#404' может облегчить поиск ошибок в HTML коде
		}
		else $s = $www_dir . rtrim($filename, '?') . $query_string;
		return $is_html_quote ? htmlspecialchars($s) : $s;
	}

	/**
	 *
	 * @param   scalar|array|null  $s
	 * @param   string|null        $delim
	 * @return  string|bool|null   returns FALSE if error occured
	 */
	public static function quote($s, $delim = null)
	{
		if (! ReflectionTypeHint::isValid()) return false;
		if (is_null($s)) return $s;

		if (is_array($s))
		{
			if ($delim === null)
			{
				trigger_error('If 1-st parameter an array, a string type expected in 2-nd paramater, ' . gettype($delim) . ' given', E_USER_WARNING);
				return false;
			}
			$s = implode($delim, $s);
		}

		return htmlspecialchars($s);
	}

	/**
	 * Convert special HTML entities back to characters
	 * @param  string|null       $s
	 * @param  int               $quote_style
	 *								ENT_COMPAT   Will convert double-quotes and leave single-quotes alone (default)
	 *								ENT_QUOTES   Will convert both double and single quotes
	 *								ENT_NOQUOTES Will leave both double and single quotes unconverted
	 * @return string|bool|null
	 */
	public static function unquote($s, $quote_style = ENT_COMPAT)
	{
		if (! ReflectionTypeHint::isValid()) return false;
		if (is_null($s)) return $s;
		return htmlspecialchars_decode($s, $quote_style);
	}

	/**
	 * Более продвинутый аналог strip_tags() для корректного вырезания тэгов из html кода.
	 * Функция strip_tags(), в зависимости от контекста, может работать некорректно.
	 * Возможности:
	 *   - корректно обрабатываются вхождения типа "a < b > c"
	 *   - корректно обрабатывается "грязный" html, когда в значениях атрибутов тэгов могут встречаться символы < >
	 *   - корректно обрабатывается разбитый html
	 *   - вырезаются комментарии, скрипты, стили, PHP, Perl, ASP код, MS Word тэги, CDATA
	 *   - автоматически форматируется текст, если он содержит html код
	 *   - защита от подделок типа: "<<fake>script>alert('hi')</</fake>script>"
	 *
	 * @param   string|array|null  $s
	 * @param   array|null         $allowable_tags    Массив тэгов, которые не будут вырезаны
	 *                                                Пример: 'b' -- тэг останется с атрибутами, '<b>' -- тэг останется без атрибутов
	 * @param   bool               $is_format_spaces  Форматировать пробелы и переносы строк?
	 *                                                Вид текста на выходе (plain) максимально приближеется виду текста в браузере на входе.
	 *                                                Другими словами, грамотно преобразует text/html в text/plain.
	 *                                                Текст форматируется только в том случае, если были вырезаны какие-либо тэги.
	 * @param   array         $pair_tags   Массив имён парных тэгов, которые будут удалены вместе с содержимым
	 *                                     См. значения по умолчанию
	 * @param   array         $para_tags   Массив имён парных тэгов, которые будут восприниматься как параграфы (если $is_format_spaces = true)
	 *                                     См. значения по умолчанию
	 * @return  string|bool|null
	 */
	public static function strip_tags(
		$s,
		array $allowable_tags = null,
		$is_format_spaces = true,
		array $pair_tags = array('script', 'style', 'map', 'iframe', 'frameset', 'object', 'applet', 'comment', 'button', 'textarea', 'select'),
		array $para_tags = array(
			#Paragraph boundaries are inserted at every block-level HTML tag. Namely, those are (as taken from HTML 4 standard)
			'address', 'blockquote', 'caption', 'center', 'dd', 'div', 'dl', 'dt', 'h1', 'h2', 'h3', 'h4', 'h5', 'li', 'menu', 'ol', 'p', 'pre', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'ul',
			#Extended
			'form', 'title',
		)

	)
	{
		if (! ReflectionTypeHint::isValid()) return false;
		if (is_null($s)) return $s;

		static $_callback_type  = false;
		static $_allowable_tags = array();
		static $_para_tags      = array();

		if (is_array($s))
		{
			if ($_callback_type === 'strip_tags')
			{
				$tag = strtolower($s[1]);
				if ($_allowable_tags)
				{
					#tag with attributes
					if (array_key_exists($tag, $_allowable_tags)) return $s[0];

					#tag without attributes
					if (array_key_exists('<' . $tag . '>', $_allowable_tags))
					{
						if (substr($s[0], 0, 2) === '</') return '</' . $tag . '>';
						if (substr($s[0], -2) === '/>')   return '<' . $tag . ' />';
						return '<' . $tag . '>';
					}
				}
				if ($tag === 'br') return "\r\n";
				if ($_para_tags && array_key_exists($tag, $_para_tags)) return "\r\n\r\n";
				return '';
			}
			trigger_error('Unknown callback type "' . $_callback_type . '"!', E_USER_ERROR);
		}

		if (($pos = strpos($s, '<')) === false || strpos($s, '>', $pos) === false)  #speed improve
		{
			#tags are not found
			return $s;
		}

		$length = strlen($s);

		#unpaired tags (opening, closing, !DOCTYPE, MS Word namespace)
		$re_tags = '~  <[/!]?+
                       (
                           [a-zA-Z][a-zA-Z\d]*+
                           (?>:[a-zA-Z][a-zA-Z\d]*+)?
                       ) #1
                       ' . self::$re_attrs . '
                       >
                    ~sxSX';

		$patterns = array(
			'/<([\?\%]) .*? \\1>/sxSX',     #встроенный PHP, Perl, ASP код
			'/<\!\[CDATA\[ .*? \]\]>/sxSX', #блоки CDATA
			#'/<\!\[  [\x20\r\n\t]* [a-zA-Z] .*?  \]>/sxSX',  #DEPRECATED  MS Word тэги типа <![if! vml]>...<![endif]>

			'/<\!--.*?-->/sSX', #комментарии

			#MS Word тэги типа "<![if! vml]>...<![endif]>",
			#условное выполнение кода для IE типа "<!--[if expression]> HTML <![endif]-->"
			#условное выполнение кода для IE типа "<![if expression]> HTML <![endif]>"
			#см. http://www.tigir.com/comments.htm
			'/ <\! (?:--)?+
                   \[
                   (?> [^\]"\']+ | "[^"]*" | \'[^\']*\' )*
                   \]
                   (?:--)?+
               >
             /sxSX',
		);
		if ($pair_tags)
		{
			#парные тэги вместе с содержимым:
			foreach ($pair_tags as $k => $v) $pair_tags[$k] = preg_quote($v, '/');
			$patterns[] = '/ <((?i:' . implode('|', $pair_tags) . '))' . self::$re_attrs . '(?<!\/)>
                               .*?
                             <\/(?i:\\1)' . self::$re_attrs . '>
                           /sxSX';
			#на больших текстах может быть PREG_BACKTRACK_LIMIT_ERROR, увеличиваем ограничение
			ini_set('pcre.backtrack_limit', 1000000);
		}

		$i = 0; #защита от зацикливания
		$max = 99;
		while ($i < $max)
		{
			$s2 = preg_replace($patterns, '', $s);
			if ((function_exists('preg_last_error') && preg_last_error() !== PREG_NO_ERROR) || $s2 === false) #PHP >= 5.1.6 support
			{
				$i = 999;
				break;
			}

			if ($i == 0)
			{
				$is_html = ($s2 != $s || preg_match($re_tags, $s2));
				if (function_exists('preg_last_error') && preg_last_error() !== PREG_NO_ERROR)
				{
					$i = 999;
					break;
				}
				if ($is_html)
				{
					if ($is_format_spaces)
					{
						/*
                          В библиотеке PCRE для PHP \s - это любой пробельный символ, а именно класс символов [\x09\x0a\x0c\x0d\x20\xa0] или, по другому, [\t\n\f\r \xa0]
                          Если \s используется с модификатором /u, то \s трактуется как [\x09\x0a\x0c\x0d\x20]
                          Браузер не делает различия между пробельными символами, друг за другом подряд идущие символы воспринимаются как один
						*/
						if (version_compare(PHP_VERSION, '5.2.0', '>='))
						{
							$s2 = preg_replace('/  [\x09\x0a\x0c\x0d]++
                                                 | <((?i:pre|textarea))' . self::$re_attrs . '(?<!\/)> #1
                                                   .+?
                                                   <\/(?i:\\1)' . self::$re_attrs . '>
                                                   \K
                                                /sxSX', ' ', $s2);
						}
						else
						{
							$s2 = preg_replace('/  [\x09\x0a\x0c\x0d]++
                                                 | ( #1
                                                     <((?i:pre|textarea))' . self::$re_attrs . '(?<!\/)> #2
                                                     .+?
                                                     <\/(?i:\\2)' . self::$re_attrs . '>
                                                   )
                                                /sxSX', '$1 ', $s2);
						}
						if ((function_exists('preg_last_error') && preg_last_error() !== PREG_NO_ERROR) || $s2 === false)
						{
							$i = 999;
							break;
						}
					}

					#массив тэгов, которые не будут вырезаны
					if ($allowable_tags) $_allowable_tags = array_flip($allowable_tags);

					#парные тэги, которые будут восприниматься как параграфы
					if ($para_tags) $_para_tags = array_flip($para_tags);
				}
			}#if

			#tags processing
			if ($is_html)
			{
				$_callback_type = 'strip_tags';
				$s2 = preg_replace_callback($re_tags, array('self', __FUNCTION__), $s2);
				$_callback_type = false;
				if ((function_exists('preg_last_error') && preg_last_error() !== PREG_NO_ERROR) || $s2 === false)
				{
					$i = 999;
					break;
				}
			}

			if ($s === $s2) break;
			$s = $s2; $i++;
		}
		if ($i >= $max) $s = strip_tags($s); #too many cycles for replace...

		if ($is_format_spaces && strlen($s) !== $length)
		{
			#remove a duplicate spaces
			$s = preg_replace('/\x20\x20++/sSX', ' ', trim($s));
			#remove a spaces before and after new lines
			$s = str_replace(array("\r\n\x20", "\x20\r\n"), "\r\n", $s);
			#replace 3 and more new lines to 2 new lines
			$s = preg_replace('/[\r\n]{3,}+/sSX', "\r\n\r\n", $s);
		}
		return $s;
	}

	/**
	 * Определяем наличие html кода (сущности не рассматриваем)
	 *
	 * @param   string|null  $s             Текст
	 * @param   array|null   $no_html_tags  Таги, которые не являются HTML
	 * @return  bool|null
	 */
	public static function is_html($s, array $no_html_tags = null)
	{
		if (! ReflectionTypeHint::isValid()) return false;
		if ($s === null) return null;
		$regexp = '~(?> <[a-zA-Z][a-zA-Z\d]*+  {no_html_tags_open_re}' . self::$re_attrs . '> # open pair tag / self-closed tag
					  | </[a-zA-Z][a-zA-Z\d]*+ {no_html_tags_close_re}>                       # closed tag
					  | <!-- .*? -->                                       # comment
					  | <![A-Z] .*? >                                      # DOCTYPE, ENTITY
					  | <\!\[CDATA\[ .*? \]\]>                             # CDATA
					  | <\? .*? \?>                                        # instructions
					  | <\% .*? \%>                                        # instructions
					  # MS Word, IE (Internet Explorer) condition tags
					  | <! (?:--)?+
						   \[
						   (?> [^\]"\'`]+
							 | "[^"]*"
							 | \'[^\']*\'
							 | `[^`]*`
						   )*+
						   \]
						   (?:--)?+
						>
					)
				   ~sxSX';
		$regexp = str_replace('{no_html_tags_open_re}',  $no_html_tags ? '(?<!<(?i:' . implode('|', $no_html_tags) . '))' : '', $regexp);
		$regexp = str_replace('{no_html_tags_close_re}', $no_html_tags ? '(?<!/(?i:' . implode('|', $no_html_tags) . '))' : '', $regexp);
		return (bool) preg_match($regexp, $s);
	}

	/**
	 * Разбивает текст на параграфы, используя для этого html тэги (<p></p>, <br />).
	 * Или, другими словами, переводит текст из text/plain в text/html.
	 * "Красная строка" (отступ в начале абзаца) поддерживается, дублирующие пробелы вырезаются.
	 *
	 * Текст возвращается без изменений если:
	 *   * текст уже содержит html код или
	 *   * текст не содержит переносов строк
	 *
	 * @see      nl2br()
	 * @link     http://daringfireball.net/projects/markdown/
	 * @param    string|null        $s             текст
	 * @param    bool               $is_single     обрамлять тэгами единственно найденный парагаграф?
	 * @param    bool               &$is_html      возвращает TRUE, если текст явл. HTML кодом, иначе FALSE
	 * @param    array              $no_html_tags  тэги, которые не являются HTML
	 * @return   string|bool|null   returns FALSE if error occured
	 */
	public static function paragraph($s, $is_single = false, &$is_html = false, array $no_html_tags = array('notypo'))
	{
		if (! ReflectionTypeHint::isValid()) return false;
		if (is_null($s)) return $s;

		#определяем наличие html кода
		$is_html = self::is_html($s, $no_html_tags);
		if (! $is_html)
		{
			#рег. выражение для разбивки текста text/plain на параграфы
			#"красная строка" (отступ в начале абзаца) поддерживается!
			$a = preg_split('/(\r\n|[\r\n])(?>[\x20\t]|\\1)+/sSX', trim($s), -1, PREG_SPLIT_NO_EMPTY);
			$a = array_map('trim', $a);
			$a = preg_replace('/[\r\n]++/sSX', "<br />\r\n", $a);
			if (count($a) > intval(! (bool)$is_single)) $s = '<p>' . implode("</p>\r\n\r\n<p>", $a) . '</p>';
			else $s = implode('', $a);
			$s = preg_replace('/\x20\x20++/sSX', ' ', $s);  #вырезаем лишние пробелы
		}
		return $s;
	}

	/**
	 * "Подсветка" найденных слов для результатов поисковых систем.
	 * Ищет все вхождения цифр или целых слов в html коде и обрамляет их заданными тэгами.
	 * Текст должен быть в кодировке UTF-8.
	 *
	 * @param   string|null       $s                  Текст, в котором искать
	 * @param   array|null        $words              Массив поисковых слов
	 * @param   bool              $is_case_sensitive  Искать с учётом от регистра?
	 * @param   string            $tpl                HTML шаблон для замены
	 * @return  string|bool|null  returns FALSE if error occured
	 */
	public static function words_highlight(
		$s,
		array $words = null,
		$is_case_sensitive = false,
		$tpl = '<span class="highlight">%s</span>')
	{
		if (! ReflectionTypeHint::isValid()) return false;
		if (is_null($s)) return $s;

		#оптимизация для пустых значений
		if (! strlen($s) || ! $words) return $s;

		#оптимизация
		#{{{
		$s2 = UTF8::lowercase($s);
		foreach ($words as $k => $word)
		{
			$word = UTF8::lowercase(trim($word, "\x00..\x20\x7f*"));
			if ($word == '' || strpos($s2, $word) === false) unset($words[$k]);
		}
		if (! $words) return $s;
		#}}}

		#d($words);
		#кэширование построения рег. выражения для "подсвечивания" слов в функции при повторных вызовах
		static $func_cache = array();
		$cache_id = md5(serialize(array($words, $is_case_sensitive, $tpl)));
		if (! array_key_exists($cache_id, $func_cache))
		{
			$re_words = array();
			foreach ($words as $word)
			{
				$is_mask = (substr($word, -1) === '*');
				if ($is_mask) $word = rtrim($word, '*');

				$is_digit = ctype_digit($word);

				#рег. выражение для поиска слова с учётом регистра или цифр:
				$re_word = preg_quote($word, '~');

				#рег. выражение для поиска слова НЕЗАВИСИМО от регистра:
				if (! $is_case_sensitive && ! $is_digit)
				{
					if (UTF8::is_ascii($word)) $re_word = '(?i:' . $re_word . ')';
					#для русских и др. букв, т. к. флаг /u и (?i:слово) не помогают :(
					else
					{
						$lc = UTF8::str_split(UTF8::lowercase($re_word));
						$uc = UTF8::str_split(UTF8::uppercase($re_word));
						$re_word = array();
						foreach ($lc as $i => $tmp)
						{
							$re_word[] = '[' . $lc[$i] . $uc[$i] . ']';
						}
						$re_word = implode('', $re_word);
					}
				}

				#d($re_word);
				if ($is_digit) $append = $is_mask ? '\d*+' : '(?!\d)';
				else $append = $is_mask ? '\p{L}*+' : '(?!\p{L})';
				$re_words[$is_digit ? 'digits' : 'words'][] = $re_word . $append;
			}

			if (array_key_exists('words', $re_words) && $re_words['words'])
			{
				#поиск вхождения слова:
				$re_words['words'] = '(?<!\p{L})  #просмотр назад (\b не подходит и работает медленнее)
                                      (?:' . implode(PHP_EOL . '| ', $re_words['words']) . ')
                                      ';
			}
			if (array_key_exists('digits', $re_words) && $re_words['digits'])
			{
				#поиск вхождения цифры:
				$re_words['digits'] = '(?<!\d)  #просмотр назад (\b не подходит и работает медленнее)
                                       (?:' . implode(PHP_EOL . '| ', $re_words['digits']) . ')
                                       ';
			}
			#d(implode(PHP_EOL . '| ', $re_words));

			$func_cache[$cache_id] = '~(?>  #встроенный PHP, Perl, ASP код
											<([\?\%]) .*? \\1>
											\K

											#блоки CDATA
                                         |  <\!\[CDATA\[ .*? \]\]>
											\K

											#MS Word тэги типа "<![if! vml]>...<![endif]>",
											#условное выполнение кода для IE типа "<!--[if lt IE 7]>...<![endif]-->":
                                         |  <\! (?>--)?
												\[
												(?> [^\]"\']+ | "[^"]*" | \'[^\']*\' )*
												\]
												(?>--)?
											>
											\K

											#комментарии
                                         |  <\!-- .*? -->
											\K

											#парные тэги вместе с содержимым
                                         |  <((?i:noindex|script|style|comment|button|map|iframe|frameset|object|applet))' . self::$re_attrs . '(?<!/)>
												.*?
											</(?i:\\2)>
											\K

											#парные и непарные тэги
                                         |  <[/\!]?+[a-zA-Z][a-zA-Z\d]*+' . self::$re_attrs . '>
											\K

											#html сущности (&lt; &gt; &amp;) (+ корректно обрабатываем код типа &amp;amp;nbsp;)
                                         |  &(?> [a-zA-Z][a-zA-Z\d]++
												| \#(?>		\d{1,4}+
														|	x[\da-fA-F]{2,4}+
													)
                                            );
											\K

										 | ' . implode(PHP_EOL . '| ', $re_words) . '
                                       )
                                      ~suxSX';
			#d($func_cache[$cache_id]);
		}
		$s = preg_replace_callback($func_cache[$cache_id],  function (array $m) use ($tpl)
															{
																return ($m[0] !== '') ? sprintf($tpl, $m[0]) : $m[0];
															}, $s);
		return $s;
	}

	/**
	 * Добавляет "водяные знаки" в html код.
	 * Дописывает "© http://domain.com/" после каждого абзаца текста,
	 * который не отображается в браузере, но успешно копируется вместе с текстом!
	 *
	 * @param   string|null       $s
	 * @return  string|bool|null  returns FALSE if error occured
	 */
	public static function watermark($s)
	{
		if (! ReflectionTypeHint::isValid()) return false;
		if (is_null($s)) return $s;

		return preg_replace_callback('~</(?: p>  (?<!<p></p>)   (?![\x00-\x20]*+</(?:td|li)>)
                                           | td> (?<!<td></td>) [\x00-\x20]*+ </tr>
                                           | li> (?<!<li></li>) [\x00-\x20]*+ </[ou]l>
                                         )
                                      ~sixSX', array('self', '_watermark'), $s);
	}

	private static function _watermark(array $m)
	{
		/*
          ЗАМЕЧАНИЕ
          Свойство 'watermark' в CSS не существует и д.б. проигнорировано браузером (Опера "ругается" в консоли ошибок).
          Попытка поместить "водяной знак" в комментарии /*...* / привела к некорректному отображению в Opera-9.27.
		*/
		static $i = 0, $url = null, $hash = null;

		if ($url === null)
		{
			#вырезаем идентификатор сессии в целях усиления безопасности!
			$url  = 'http://' . $_SERVER['SERVER_NAME'] . URL::replace_arg($_SERVER['REQUEST_URI'], array(), $is_use_sid = false);
			$hash = base_convert(md5($url), 16, 36);
		}
		#стили не изменять, т.к. протестировано (в декабре 2008) и корректно работает во всех современных браузерах!
		return '<noindex><span class="fake invisible" style="position:absolute;display:block;width:0;height:0;text-indent:-2989px;font:normal 0 sans-serif;opacity:0.01;filter:alpha(opacity=1);watermark:' . $hash . ',' . dechex(++$i) . '">&nbsp;&copy;&nbsp;' . $url . '</span></noindex>' . $m[0];
	}

	/**
	 * Удаляет лишние тэги, которые не могут быть вложены друг в друга, проверяет парность тэгов.
	 * Используется простой и быстрый алгоритм.
	 *
	 * ПРИМЕРЫ
	 *   "<noindex> ... <noindex> ... </noindex> ... </noindex>" => "<noindex> ...  ...  ... </noindex>"
	 *
	 * ЗАМЕЧАНИЕ
	 *   Рекомендуется использовать совместно и после вызова self::normalize_links()
	 *
	 * Яндекс и тег noindex.
	 *   Робот Яндекса поддерживает тег noindex, который позволяет не индексировать заданные (служебные) участки текста.
	 *   В начале служебного фрагмента поставьте — <noindex>, а в конце — </noindex>, и Яндекс не будет индексировать данный участок текста.
	 *   Тег noindex чувствителен к вложенности!
	 *   http://help.yandex.ru/webmaster/?id=995294
	 *
	 * @param   string|array|null  $s              Html код
	 * @param   array|null         &$invalid_tags  Возвращает массив некорректных парных тэгов, если такие есть
	 * @param   array|null         &$deleted_tags  Возвращает массив удалённых тэгов, где ключами явл. тэги, а значениями кол-во > 0
	 * @param   array              $tags           Эти парные тэги не м.б. вложены друг в друга (XHTML)
	 *                                             Каждый элемент массива -- регулярное выражение
	 * @return  string|bool|null   returns FALSE if error occured
	 */
	public static function normalize_tags($s,
		array &$invalid_tags = null,
		array &$deleted_tags = null,
		array $tags = array(
			'html', 'head', 'body',
			'title', 'h[1-6]',
			'span', 'div',
			'form', 'textarea', 'button', 'option', 'label', 'select', #формы
			'strong', 'em', 'big', 'small', 'sub', 'sup', 'tt',
			'[abius]', 'bdo', 'caption', 'del', 'ins',
			'script', 'noscript', 'style', 'map', 'applet', 'object',
			'table', 't[rhd]', #таблицы
			'nobr', 'noindex', 'wiki', 'notypo', 'comment',
		)
	)
	{
		if (! ReflectionTypeHint::isValid()) return false;
		if (is_null($s)) return $s;

		static $_opened_tags  = array();
		static $_deleted_tags = array();
		//static $_is_nofollow  = false;

		if (is_array($s) && $invalid_tags === null)  #callback?
		{
			if ($s[0] === '' || ! isset($s[2])) return $s[0];

            $t = substr($s[0], 0, 2);
            if ($t === '<?' || $t === '<%' || $t === '<!') return $s[0];

			$tag = strtolower($s[2]);
			if (! array_key_exists($tag, $_opened_tags)) $_opened_tags[$tag] = 0;
			$o =& $_opened_tags[$tag];
			if ($s[1] !== '/')
			{
				#tag has opened
				$o++;
				if ($o > 1)
				{
					if (! array_key_exists($tag, $_deleted_tags)) $_deleted_tags[$tag] = 0;
					$_deleted_tags[$tag]++;
					return '';
				}
				/*
                //DEPRECATED
                if ($tag === 'a')
                {
                    if (empty($_opened_tags['noindex']) &&  #не допускаем вложенности тэга <noindex> друг в друга
                        preg_match('~(?<![-a-z\d_])rel
                                     [\x00-\x20\x7f]*+
                                     =
                                     [\x00-\x20\x7f]*+
                                     (?:
                                       "[^"]*? (?<![-a-z\d_])nofollow[^-a-z\d_]
                                       | \'[^\']*? (?<![-a-z\d_])nofollow[^-a-z\d_]
                                       | nofollow[^-a-z\d_]
                                     )
                                    ~sxiSX', $s[0]))
                    {
                        $_is_nofollow = true;
                        $s[0] = '<noindex>' . $s[0];
                    }

                }
				*/
			}
			else
			{
				#tag has closed
				$o--;
				if ($o > 0)
				{
					if (! array_key_exists($tag, $_deleted_tags)) $_deleted_tags[$tag] = 0;
					$_deleted_tags[$tag]++;
					return '';
				}
				/*
                //DEPRECATED
                if ($tag === 'a' && $_is_nofollow)
                {
                    $_is_nofollow = false;
                    $s[0] .= '</noindex>';
                }
				*/
			}
			return $s[0];
		}
		$s = preg_replace_callback('~(?>
											<(/)?+                                  #1
												((?i:' . implode('|', $tags) . '))  #2
												(?(1)|' . self::$re_attrs . '(?<!/))
											>

											#встроенный PHP, Perl, ASP код
										|	<([?%]) .*? \\3>  #3
			
											#блоки CDATA
										|	<!\[CDATA\[ .*? \]\]>

											#MS Word тэги типа "<![if! vml]>...<![endif]>",
											#условное выполнение кода для IE типа "<!--[if lt IE 7]>...<![endif]-->"
										|	<! (?>--)?
												\[
												(?> [^\]"\']+ | "[^"]*" | \'[^\']*\' )*
												\]
												(?>--)?
											>

											#комментарии
										|	<!-- .*? -->
									)
								~sxSX', array('self', __FUNCTION__), $s);
		$invalid_tags = array();
		foreach ($_opened_tags as $tag => $count) if ($count !== 0) $invalid_tags[] = $tag;
		$deleted_tags = $_deleted_tags;

		#restore static values
		$_opened_tags  = array();
		$_deleted_tags = array();

		return $s;
	}

	/**
	 * Check to valid XHTML 1.1 Strict
	 * http://www.w3.org/TR/2001/REC-xhtml11-20010531/
	 *
	 * Назначение
	 *   Проверка на XHTML позволяет придерживаться современного стандарта вёрстки веб-страниц,
	 *   избегать грубых ошибок верстальщика (или автотипографики) и возможного "разваливания"
	 *   дизайна сайта в браузере как следствия.
	 *   Побочным положительным эффектом можно считать защиту от СПАМа в веб-формах с "кривым" html спамеров.
	 *
	 * Ограничения
	 *   Этот простой парсер проверяет только синтаксис!
	 *   Проверяется корректность вложенности пустых и парных тэгов, допустимых атрибутов.
	 *   Логическая вложенность парных тэгов почти не проверяется.
	 *   Так, например, нет проверки на то, что тэг <a> не может содержать другие тэги <a>.
	 *   Не проверяется наличие обязательных аттрибутов для некоторых тэгов (например, для <img>).
	 *   Не делается проверка на дублирующие атрибуты, когда один и тот же атрибут встречается в тэге несколько раз.
	 *
	 * Примеры использования
	 *   echo HTML::is_xhtml('<B class=aaa><i>test</B></i><img src=""/>') ? 'OK' : 'ERROR';
	 *   $s = '<p id="<p>">
	 *           <img src=""/>
	 *           1 < 3 > 2 <!4
	 *           <a href="#">link <!-- <!--*--> </a>
	 *           test <i><b>bold italic</b></i>
	 *         </p>';
	 *   echo HTML::is_xhtml($s) ? 'OK' : 'ERROR';
	 *
	 * Замечания
	 *   self::is_xhtml() удобно использовать совместно с Html Tidy (http://ru2.php.net/tidy)
	 *   Функция явл. отличным учебным пособием для изучения регулярных выражений PCRE!
	 *
	 * @param   string|null  $s           Html код
	 * @param   bool         $is_strict   Строгая проверка XHTML (настоятельно рекомендуется):
	 *                                      * делается проверка на разрешённые тэги из спецификации
	 *                                      * имена тэгов и атрибутов д.б. только в нижнем регистре
	 *                                      * значения атрибутов тэгов д.б. только в кавычках
	 *                                      * символы < > в значениях атрибутов тэгов не допускаются (д.б. сущности &lt; &gt;)
	 *                                      * символы < > за пределами тэгов не допускаются (д.б. сущности &lt; &gt;)
	 *                                        если эти символы нужно использовать, используйте содержимое #CDATA (например, для тэгов script и style)
	 *                                      * MS Word и IE (Internet Explorer) условные тэги не допускаются
	 * @param   bool         $strict_is_legacy_allow    Allow tags and attributes that were already deprecated in previous versions of HTML and XHTML
	 * @param   int|null     $error_offset              Возвращает смещение в байтах в случае ошибки.
	 *                                                  Работает надёжно только для первой вложенности тэгов.
	 * @param   array        $strict_pair_tags_extra
	 * @param   array        $strict_empty_tags_extra
	 * @param   array        $strict_attrs_extra
	 * @return  bool|null    returns FALSE if error occured
	 */
	public static function is_xhtml($s,
									$is_strict = true,
									$strict_is_legacy_allow = true,
									$error_offset = null,
									#дополнительные тэги и атрибуты:
									array $strict_pair_tags_extra  = array('nobr', 'notypo', 'wiki'),
									array $strict_empty_tags_extra = array('typo', 'page'),
									array $strict_attrs_extra      = array(
										'md5', /*'crc32', 'sha1',*/                                      #Text_Typograph
										'time', 'speed', 'length', 'char_length', 'created', 'version',  #Text_Typograph
										#'if', 'blockif',                                                 #html_template  DEPRECATED
									)
	)
	{
		if (! ReflectionTypeHint::isValid()) return false;
		if (is_null($s)) return $s;

		if (($pos = strpos($s, '<')) === false || strpos($s, '>', $pos) === false)  #speed improve
		{
			return true; #tags are not found
		}
		if ($is_strict)
		{
			$re_attr_names = '
                             #Common (Core + I18N + Style + Events)
                               xml:space|class|id|title  #Core
                               |dir|xml:lang             #I18N
                               |style                    #Style
                               #|on[a-z]{3,30}+          #Events
                               #Events
                               |on(?:abort
                                    |activate
                                    |afterprint
                                    |afterupdate
                                    |beforeactivate
                                    |beforecopy
                                    |beforecut
                                    |beforedeactivate
                                    |beforeeditfocus
                                    |beforepaste
                                    |beforeprint
                                    |beforeunload
                                    |beforeupdate
                                    |blur
                                    |bounce
                                    |cellchange
                                    |change
                                    |click
                                    |contextmenu
                                    |controlselect
                                    |copy
                                    |cut
                                    |dataavailable
                                    |datasetchanged
                                    |datasetcomplete
                                    |dblclick
                                    |deactivate
                                    |drag
                                    |dragend
                                    |dragenter
                                    |dragleave
                                    |dragover
                                    |dragstart
                                    |drop
                                    |error
                                    |errorupdate
                                    |filterchange
                                    |finish
                                    |focus
                                    |focusin
                                    |focusout
                                    |help
                                    |keydown
                                    |keypress
                                    |keyup
                                    |layoutcomplete
                                    |load
                                    |losecapture
                                    |mousedown
                                    |mouseenter
                                    |mouseleave
                                    |mousemove
                                    |mouseout
                                    |mouseover
                                    |mouseup
                                    |mousewheel
                                    |move
                                    |moveend
                                    |movestart
                                    |paste
                                    |propertychange
                                    |readystatechange
                                    |reset
                                    |resize
                                    |resizeend
                                    |resizestart
                                    |rowenter
                                    |rowexit
                                    |rowsdelete
                                    |rowsinserted
                                    |scroll
                                    |select
                                    |selectionchange
                                    |selectstart
                                    |start
                                    |stop
                                    |submit
                                    |unload
                                   )
                             #Structure Module
                               |profile|version|xmlns
                             #Text Module
                               |cite
                             #Hypertext Module
                               |accesskey|charset|href|hreflang|rel|rev|tabindex|type|target  #<a>
                             #Applet Module
                               |alt|archive|code|codebase|height|object|width  #<applet>
                               |name|type|value|valuetype                      #<param>
                             #Edit Module
                               |cite|datetime  #<del>, <ins>
                             #Bi-directional Text Module
                               |dir|xml:lang
                             #Basic Forms Module
                               |accept|accept-charset|action|method|enctype                    #<form>
                               |accept|accesskey|alt|checked|disabled|maxlength|name|readonly|size|src|tabindex|type|value  #<input>
                               |disabled|multiple|name|size|tabindex                           #<select>
                               |disabled|label|selected|value                                  #<option>
                               |accesskey|cols|disabled|name|readonly|rows|tabindex            #<textarea>
                               |accesskey|disabled|name|tabindex|type|value                    #<button>
                               |accesskey|for                                                  #<label>
                               |accesskey                                                      #<legend>
                               |disabled|label                                                 #<optgroup>
                             #Tables Module
                               |border|cellpadding|cellspacing|frame|rules|summary|width           #<table>
                               |abbr|align|axis|char|charoff|colspan|headers|rowspan|scope|valign  #<td>, <th>
                               |align|char|charoff|valign                                          #<tr>
                               |align|char|charoff|span|valign|width                               #<col>, <colgroup>
                               |align|char|charoff|valign                                          #<tbody>, <thead>, <tfoot>
                             #Image Module
                               |alt|height|longdesc|src|width|usemap|ismap  #<img>
                             #Client-side Image Map Module
                               |accesskey|alt|coords|href|nohref|shape|tabindex  #<area>
                               |class|id|title                                   #<map>
                             #Object Module
                               |archive|classid|codebase|codetype|data|declare|height|name|standby|tabindex|type|width  #<object>
                               |id|name|type|value|valuetype                                                            #<param>
                             #Frames Module
                               |cols|rows                                                                   #<frameset>
                               |frameborder|longdesc|marginheight|marginwidth|noresize|scrolling|src  #<frame>
                             #Iframe Module
                               |frameborder|height|longdesc|marginheight|marginwidth|scrolling|src|width  #<iframe>
                             #Metainformation Module
                               |content|http-equiv|id|name|scheme  #<meta>
                             #Scripting Module
                               |charset|defer|id|src|type  #<script>
                             #Style Sheet Module
                               |id|media|title|type  #<style>
                             #Link Module
                               |charset|href|hreflang|media|rel|rev|type  #<link>
                             #Base Module
                               |href|id  #<base>
                             #Legacy Module
                               #This module is deprecated
                             ';

			#http://www.w3.org/TR/xhtml-modularization/abstract_modules.html
			$re_pair_tags = ' #Structure Module
                                body|head|html#|title
                              #Text Module
                                |abbr|acronym|address|blockquote|cite|code|dfn|div|em|h[1-6]|kbd|p|pre|q|samp|span|strong|var
                              #Hypertext Module
                                |a
                              #List Module
                                |dl|dt|dd|ol|ul|li
                              #Presentation Module
                                |b|big|i|small|sub|sup|tt
                              #Edit Module
                                |del|ins
                              #Bidirectional Text Module
                                |bdo
                              #Forms Module
                                |button|fieldset|form|label|legend|select|optgroup #|option|textarea
                              #Table Module
                                |caption|colgroup|table|tbody|td|tfoot|th|thead|tr
                              #Client-side Image Map Module
                                |map
                              #Object Module
                                |object
                              #Frames Module
                                |frameset|noframes
                              #Iframe Module
                                |iframe
                              #Scripting Module
                                |noscript #|script
                              #Stylesheet Module
                                #|style
                            ';
			$re_empty_tags = 'br|param|hr|input|col|img|area|frame|meta|link|base';

			if ($strict_attrs_extra)      $re_attr_names .= '|' . implode('|', $strict_attrs_extra);
			if ($strict_pair_tags_extra)  $re_pair_tags  .= '|' . implode('|', $strict_pair_tags_extra);
			if ($strict_empty_tags_extra) $re_empty_tags .= '|' . implode('|', $strict_empty_tags_extra);
			if ($strict_is_legacy_allow)
			{
				#deprecated:
				$re_attr_names .= '|color|face|id|size|compact|prompt|alink|background|bgcolor|link|text|vlink|clear|align|noshade|nowrap|width|height|border|hspace|vspace|type|value|start|language';
				$re_pair_tags  .= '|center|dir|font|menu|s|strike|u';
				$re_empty_tags .= '|basefont|isindex';
			}

			$re_attrs = '(?>
                           (?>[\x00-\x20\x7f]+|\xc2\xa0)++  #spaces
                           #(?:xml:)?+ [a-z]{3,30}+         #name
                           (?:' . $re_attr_names . ')       #name
                           =                                #equal
                           (?> "[^"<>]*+"                   #value in ""
                             | \'[^\'<>]*+\'                #value in \'\'
                           )
                         )*+
                         (?>[\x00-\x20\x7f]+|\xc2\xa0)*+';

			#PCRE 7.0+ / PHP >= 5.2.6
			$re_html = '~
                         ^(?<main>
                             (?> # pair of tags (have any tags)
                                 <(?<tag1>' . $re_pair_tags . ')' . $re_attrs . '>
                                   (?&main)
                                 </\g{tag1}>

                                 #CDATA
                               | (?<cdata> <!\[CDATA\[ .*? \]\]>)

                                 # pair of tags (no have tags)
                               | <(?<tag2>script|style|option|textarea|title)' . $re_attrs . '>
                                   [^<>]*+
                                   (?: (?&cdata) )?+
                                   [^<>]*+
                                 </\g{tag2}>

                                 # self-closing tag
                               | <(?:' . $re_empty_tags . ')' . $re_attrs . '/>

                                 # non-tag stuff
                               | [^<>]++

                                 # comment
                               | <!-- .*? -->

                                 # DOCTYPE, ENTITY
                               | <![A-Z] .*? >

                                 # instructions (PHP, Perl, ASP)
                               | <\? .*? \?>
                               | <%  .*?  %>

                             )*+
                         )
                        ~sxSX';
		}
		else # ! $is_strict
		{
			#PCRE 7.0+ / PHP >= 5.2.6
			$re_html = '~
                         ^(?<main>
                             (?> # pair of tags (have any tags)
                                 <(?<tag1>[a-zA-Z][a-zA-Z\d]*+) (?<!<script|<style|<option|<textarea|<title) ' . self::$re_attrs . ' (?<!/)>
                                   (?&main)
                                 </\g{tag1}>

                                 # pair of tags (no have tags)
                               | <(?<tag2>script|style|option|textarea|title) ' . self::$re_attrs . ' (?<!/)>
                                   .*?
                                 </\g{tag2}>

                                 # self-closing tag
                               | <[a-zA-Z][a-zA-Z\d]*+ ' . self::$re_attrs . ' (?<=/)>

                                 # non-tag stuff (part 1)
                               | [^<]++

                                 # non-tag stuff (part 2)
                               | (?! </?+[a-zA-Z]   # open/close tags
                                   | <!(?-i:[A-Z])  # DOCTYPE/ENTITY
                                   | <[\?%\[]       # instructions; MS Word, IE
                                   | <!--           # comments
                                 ).

                                 # comment
                               | <!-- .*? -->

                                 # DOCTYPE, ENTITY
                               | <!(?-i:[A-Z]) .*? >

                                 # instructions (PHP, Perl, ASP)
                               | <\? .*? \?>
                               | <%  .*?  %>

                                 # MS Word, IE (Internet Explorer) condition tags
                               | <! (?:--)?+
                                    \[
                                    (?> [^\]"\'`]+
                                      | "[^"]*"
                                      | \'[^\']*\'
                                      | `[^`]*`
                                    )*+
                                    \]
                                    (?:--)?+
                                 >

                             )*+
                         )
                        ~sxiSX';
		}
		if (! preg_match($re_html, $s, $m)) return false;
		if (strlen($s) === strlen($m[0])) return true;
		$error_offset = strlen($m[0]);
		return false;
		//return (bool)preg_match($re_html, $s);
	}

	/**
	 * Функция фильтрует html код, вырезая потенциально опасный html код по принципу "запрещено всё, за исключением..."
	 * Отслеживаются тэги, атрибуты, значения атрибутов (протоколы в ссылках)
	 * Парные тэги (script|style|comment|button|map|iframe|frameset|object|applet) всегда заперещены и вырезаются вместе с содержимым.
	 * Дополнительно приводятся в порядок (к стандарту спецификации HTML/4) значения атрибутов тэгов: они д.б. в двойных кавычках.
	 * Функция корректно работает со всеми однобайтовыми кодировками и многобайтовой UTF-8 (более предпочтительный вариант).
	 *
	 * @param   string|null  $s            html код
	 * @param   array        $tags         разрешённые тэги
	 * @param   array        $attrs        разрешённые атрибуты внутри тэгов
	 * @param   array        $attr_links   атрибуты, в которых ставятся ссылки на файлы и проверяются разрешённые протоколы
	 * @param   array        $protocols    разрешённые протоколы
	 * @return  string|bool|null           returns FALSE if error occured
	 */
	public static function safe(
		$s,
		array $tags       = array('p', 'a', 'b', 'strong', 'i', 'em', 'u', 's', 'br', 'wbr', 'ol', 'ul', 'li', 'tt', 'sup', 'sub', 'pre', 'code', 'img', 'nobr', 'font', 'blockquote', 'noindex'),
		array $attrs      = array('class', /*'style',*/ 'align', 'target', 'title', 'href', 'src'/*, 'dynsrc', 'lowsrc'*/, 'border', 'alt', 'type', 'color'),
		array $attr_links = array('action', 'background', 'codebase', 'dynsrc', 'lowsrc', 'href', 'src'),
		array $protocols  = array('ed2k', 'file', 'ftp', 'gopher', 'http', 'https', 'irc', 'mailto', 'news', 'nntp', 'telnet', 'webcal', 'xmpp', 'callto')
	)
	{
		if (! ReflectionTypeHint::isValid()) return false;
		if (is_null($s)) return $s;

		if (($pos = strpos($s, '<') === false) || strpos($s, '>', $pos) === false)  #оптимизация скорости
		{
			#тэги не найдены
			return $s;
		}
		#хэшируем тэги и аттрибуты для увеличения скорости (доступ к элементам через array_key_exists())
		self::$_safe_tags       = array_flip($tags);
		self::$_safe_attrs      = array_flip($attrs);
		self::$_safe_attr_links = array_flip($attr_links);
		self::$_safe_protocols  = array_flip($protocols);

		#вырезаются комментарии и парные тэги: скрипты, стили, аплеты, флэш, фреймы, кнопки;
		$rules = array(
			'/<([\?\%]).*?\\1>/sSX',      #встроенный PHP, Perl, ASP код
			#'/<\!\[CDATA\[.*?\]\]>/sSX', #блоки CDATA (закомментировано, см. нижеследующее рег. выражение)
			'/<\!\[[a-zA-Z].*?\]>/sSX',   #MS Word тэги типа <![if! vml]><![endif]>
			'/<\!--.*?-->/sSX',           #комментарии
			#парные тэги вместе с содержимым:
			'/ <((?i:title|script|style|comment|button|map|iframe|frameset|object|applet))' . self::$re_attrs . '(?<!\/)>
                 .*?
               <\/(?i:\\1)>
             /sxSX',
		);
		$s = preg_replace($rules, '', $s);

		#символы могут быть записаны как html сущности, декодируем их
		$s = self::entity_decode($s);
		#d($s);

		$s = preg_replace_callback('/<
                                      ([\/\!]?+)                     #1 открывающий или закрывающий тэг, !DOCTYPE
                                      ([a-zA-Z][a-zA-Z\d]*+)         #2 тэг
                                      (' . self::$re_attrs . ')  #3 атрибуты
                                     >
                                    /sxSX', array('self', '_safe_tags_callback'), $s);

		#освобождаем память
		self::$_safe_tags = self::$_safe_attrs = self::$_safe_attr_links = self::$_safe_protocols = null;
		return trim($s, "\x00..\x20\x7f");
	}

	/**
	 * Приватная функция для обработки тэгов
	 * Заодно приводим к стандарту HTML-4.0:
	 *   - название тэга и атрибута в нижний регистр
	 *   - значение атрибута тэга д.б. в кавычках
	 */
	private static function _safe_tags_callback(array $m)
	{
		#d($m);
		$tag = strtolower($m[2]);
		if (! array_key_exists($tag, self::$_safe_tags)) return '';
		preg_match_all('/(?<=[\x20\r\n\t]|\xc2\xa0)     #пробельные символы (д.б. обязательно)
                         ([a-zA-Z][a-zA-Z\d\:\-]*+)     #1 название атрибута
                         (?>[\x20\r\n\t]++|\xc2\xa0)*+  #пробельные символы (необязательно)
                         (?>\=
                           (?>[\x20\r\n\t]++|\xc2\xa0)*  #пробельные символы (необязательно)
                           (   "[^"]*+"
                             | \'[^\']*+\'
                             | `[^`]*+`
                             | [^\x20\r\n\t]*+  #значение атрибута без кавычек и пробельных символов
                           )                    #2 значение атрибута
                         )?
                        /sxSX', $m[3], $matches, PREG_SET_ORDER);
		$attrs = array();
		foreach ($matches as $i => $a)
		{
			if (! array_key_exists(2, $a)) continue;
			list (, $attr, $value) = $a;
			$attr = strtolower($attr);
			if (! array_key_exists($attr, self::$_safe_attrs) || array_key_exists($attr, $attrs)) continue;
			if (strpos('"\'`', substr($value, 0, 1)) !== false) $value = trim(substr($value, 1, -1), "\x00..\x20\x7f");
			if (strpos($value, '&') !== false) $value = htmlspecialchars_decode($value, ENT_QUOTES);
			if ( array_key_exists($attr, self::$_safe_attr_links) &&  #атрибут явл. ссылкой на файл
				preg_match('/^([a-zA-Z\d]++)[\x20\r\n\t]*+\:/sSX', $value, $p) &&  #ссылка содержит протокол
				! array_key_exists(strtolower($p[1]), self::$_safe_protocols) #протокол не явл. разрешённым
			) continue;
			$attrs[$attr] = ' ' . $attr . '="' . htmlspecialchars($value) . '"';
		}
		return '<' . $m[1] . $tag . implode('', $attrs) . (substr($m[0], -2, 2) === '/>' ? ' />' : '>');
	}

	/**
	 * Нормализует URL ссылки в атрибутах тэгов html:
	 *   * Проверяет корректность URL ссылок.
	 *     При проверке URL действует следующее дополнительное правило: если путь существует, то он должен начинаться с корня (/).
	 *   * Заменяет в URL один абсолютный путь на другой (при необходимости).
	 *     Это касается только "своих" ссылок (текущего протокола, хоста и порта).
	 *   * Добавляет или удаляет текущий протокол, хост и порт (при необходимости).
	 *   * Для "чужих" ссылок дописывает в тэги атрибуты rel="nofollow" и target="_blank"
	 *   * Возвращает корректные и некорректные ссылки
	 *
	 * ЗАМЕЧАНИЕ 1
	 *   Для консольных скриптов нужно установить следующие переменные (пример):
	 *   $_SERVER['SERVER_PROTOCOL'] = 'http';
	 *   $_SERVER['HTTP_HOST']       = 'www.bfm.ru';
	 *	 $_SERVER['SERVER_PORT']	 = 80;
	 *
	 * ЗАМЕЧАНИЕ 2
	 *   Рекомендуется использовать совместно и до вызова self::normalize_tags()
	 *
	 * TODO: обработка стилей
	 *
	 * @link    http://help.yandex.ru/webmaster/?id=1111858   Тег <noindex>, атрибут rel="nofollow" тега <a>
	 * @param   string|null   $s             html код
	 * @param   string|null   $our_links_re  рег. выражение для списка ссылок, которые считаются "своими",
	 *                                       для своих доменов rel="nofollow" в тэги не дописывается
	 *                                       пример:
	 *                                       ~^[a-z][-a-z\d_]{2,19}+ (?<![-_]) :\/\/  #протокол
	 *                                         (?>[^\.]+\.)*                          #домены >= 3 уровня
	 *                                         (?:bfm|businessfm|dombfm|b-fm|kinofm|bfmgazeta|gazetabfm|unitedm|adv-um) #домен 2 уровня
	 *                                         \.ru(?![-a-z\d_])                      #домен 1 уровня
	 *                                       ~sxiSX
	 * @param   string|null   $path_search   что заменять (путь с начала строки, например, "/www/project_name/")
	 * @param   string|null   $path_replace  на что заменять (путь с начала строки, например, "~/")
	 * @param   array|null    $host_trans    ассоц. массив для замены хостов, используется для псевдонимов,
	 *                                       например: array('bfm.ru' => 'www.bfm.ru')
	 * @param   bool          $is_add_host   добавить или удалить текущий хост?
	 * @param   bool          $is_add_extra  для "чужих" ссылок дописывает в тэги атрибуты rel="nofollow" и target="_blank"
	 * @param   array|null    $valid_links   возвращает ассоц. массив всех корректных ссылок,
	 *                                       где ключами явл. ссылки, а значениями их заголовки
	 *                                       (из атрибутов "title" и "alt"), если он есть или NULL в противном случае
	 * @param   array|null    $broken_links  возвращает ассоц. массив всех НЕкорректных ссылок
	 *                                       где ключами явл. ссылки, а значениями их кол-во
	 * @return  string|bool|null             returns FALSE if error occured
	 */
	public static function normalize_links(
		$s,
		$our_links_re = null,
		$path_search  = null,
		$path_replace = null,
		$host_trans   = null,
		$is_add_host  = false,
		$is_add_extra = false,
		array &$valid_links  = null,
		array &$broken_links = null)
	{
		if (! ReflectionTypeHint::isValid()) return false;
		if (is_null($s)) return $s;

		$valid_links = array();
		if (($pos = strpos($s, '<')) === false || strpos($s, '>', $pos) === false) return $s; #speed improve

		if ($path_search !== null && ! preg_match('~^/(?:[^\x00-\x20\x7f/\\\\]++/)*+$~sSX', $path_search))
		{
			trigger_error('Invalid format in 2-nd parameter', E_USER_WARNING);
			return false;
		}
		if ($path_replace !== null && ! preg_match('~^[^\x00-\x20\x7f]++$~sSX', $path_replace))
		{
			trigger_error('Invalid format in 3-rd parameter', E_USER_WARNING);
			return false;
		}

		self::$_normalize_links = array(
			'our_links_re' => $our_links_re,
			'path_search'  => $path_search,
			'path_replace' => $path_replace,
			'host_trans'   => $host_trans,
			'is_add_host'  => $is_add_host,
			'is_add_extra' => $is_add_extra,
			'valid_links'  => array(),
			'broken_links' => array(),
		);

		$re_tags = implode('|', array_keys(self::$url_tags));

		$s = preg_replace_callback('~<((?i:' . $re_tags . '))                  #1 тэг
                                      (?!(?>[\x00-\x20\x7f]+|\xc2\xa0)*+/?+>)  #атрибуты должны существовать!
                                      (' . self::$re_attrs . ')                #2 атрибуты
                                     >
                                    ~sxSX', array('self', '_normalize_links_tags'), $s);
		$valid_links  = self::$_normalize_links['valid_links'];
		$broken_links = self::$_normalize_links['broken_links'];
		#освобождаем память
		self::$_normalize_links = null;
		return $s;
	}

	#приватная функция для обработки тэгов
	private static function _normalize_links_tags(array $m)
	{
		self::$_normalize_links['tag'] = strtolower($m[1]);
		unset(self::$_normalize_links['attr.title'],
			self::$_normalize_links['attr.link'],
			self::$_normalize_links['attr.rel']);
		$m[2] = preg_replace_callback('~(?<![a-zA-Z\d])                  #предыдущий символ
                                        ((?>[\x00-\x20\x7f]+|\xc2\xa0)*+) #1 пробелы (необязательно)
                                        ((?i:' . implode('|', self::_url_attrs()) . '))   #2 атрибут
                                        (?>[\x00-\x20\x7f]+|\xc2\xa0)*+  #пробелы (необязательно)
                                        =
                                        (?>[\x00-\x20\x7f]+|\xc2\xa0)*+  #пробелы (необязательно)
                                        (   "[^"]+"
                                          | \'[^\']+\'
                                          | `[^`]+`
                                          | ([^"\'`\x00-\x20\x7f]++)  #4 значение атрибута без кавычек и пробельных символов
                                        ) #3 значение атрибута (не пустое!)
                                       ~sxSX', array('self', '_normalize_links_attrs'), $m[2]);
		#если ссылка правильная
		if (isset(self::$_normalize_links['attr.link']))
		{
			if (! array_key_exists(self::$_normalize_links['attr.link'], self::$_normalize_links['valid_links']))
			{
				self::$_normalize_links['valid_links'][self::$_normalize_links['attr.link']] = @self::$_normalize_links['attr.title'];
			}
			if (self::$_normalize_links['is_add_extra'])
			{
				$rels = array();
				if (in_array(self::$_normalize_links['tag'], array('a', 'area', 'link'))
					&& ! URL::is_current_host(self::$_normalize_links['attr.link'], $is_check_scheme = false, $is_check_port = false)
					&& (self::$_normalize_links['our_links_re'] === null || ! preg_match(self::$_normalize_links['our_links_re'], self::$_normalize_links['attr.link']))
				)
				{
					#"nofollow" is used by Google, to specify that the Google search spider should not follow that link (mostly used for paid links)
					$rels[] = 'nofollow';
					if (self::$_normalize_links['tag'] !== 'link') $m[2] .= ' target="_blank"';
				}
				if (isset(self::$_normalize_links['attr.rel'])) $rels[] = trim(str_replace('nofollow', '', self::$_normalize_links['attr.rel']));
				if ($rels) $m[2] .= ' rel="' . htmlspecialchars(trim(implode(' ', $rels))) . '"';
			}
		}

		$tag = '<' . $m[1] . $m[2] . '>';
		return $tag;
	}

	#приватная функция для обработки атрибутов
	#заодно приводим код к стандарту HTML-4.0 (значение атрибута тэга д.б. в кавычках)
	private static function _normalize_links_attrs(array $m)
	{
		$attr = strtolower($m[2]);

		#проверяем соответствие атрибутов и тэгов
		if (strpos(self::$url_tags[self::$_normalize_links['tag']], $attr) === false) return $m[0];

		if ($m[1] === '') $m[1] = ' ';

		#теоретически в названии файла могут использоваться юникод-символы, но нам в первую очередь нужно это:
		#htmlspecialchars_decode() + декодируем DEC и HEX сущности
		$value = trim(isset($m[4]) ? $m[3] : substr($m[3], 1, -1), "\x00..\x20\x7f");
		$value = self::entity_decode($value, $is_htmlspecialchars = true);

		if ($attr === 'rel' || $attr === 'target')
		{
			if (! self::$_normalize_links['is_add_extra']) return $m[1] . $m[2] . '="' . htmlspecialchars($value) . '"';
			self::$_normalize_links['attr.' . $attr] = $value;
			return '';
		}
		if ( ($attr === 'title' || $attr === 'alt') &&
			! array_key_exists('attr.title', self::$_normalize_links) ) self::$_normalize_links['attr.title'] = $value;
		else
		{
			#исправляем частые ошибки в протоколе (с пропуском двойного слэша и буквы)
			$url = $value;
			$url = preg_replace('~^[a-z][-a-z\d_]{2,19}+(?<![-_]):/(?!/)~siSX', '$0/', $url);
			$url = preg_replace('~^htt?+p:?+//~siSX', 'http://', $url);

			$url = @parse_url($url);
			$url_parsed = self::_normalize_links_parse($url, $is_fragment_only);
			if (is_array($url_parsed)) $url_parsed = URL::build($url_parsed);
			if (is_string($url_parsed))
			{
				$value = $url_parsed;
				if (! $is_fragment_only) #только якоря на текущую страницу нас не интересуют
				{
					#отрезаем якорь
					if (array_key_exists('fragment', $url)) $url_parsed = substr($url_parsed, 0, -1 * strlen('#' . $url['fragment']));
					self::$_normalize_links['attr.link'] = $url_parsed;
				}
			}
			else
			{
				if (! array_key_exists($value, self::$_normalize_links['broken_links'])) self::$_normalize_links['broken_links'][$value] = 1;
				else self::$_normalize_links['broken_links'][$value]++;
			}
		}

		/*
        TODO
        if ( $attr == 'style' &&
             strpos(str_replace('\\', '/', $value), self::$_normalize_links['path_search']) !== false &&     #оптимизация скорости
             (strpos($value, 'url(') !== false || strpos($value, 'src=') !== false)  #оптимизация скорости
           )
        {
            #поддержка разного синтаксиса:
            $patterns = array(
                #url(filename)
                '/(?<![a-zA-Z\d])((?i:url)\()'   . self::$_normalize_links_re_uri_prefix . '([^)]*\))/sxSX',
                #url('filename') ~ background-image:url('/project/www/img/sunflower_alpha_border.png');
                '/(?<![a-zA-Z\d])((?i:url)\(\')' . self::$_normalize_links_re_uri_prefix . '([^\']*\'\))/sxSX',
                #src='filename'  ~ filter:progid:DXImageTransform.Microsoft.AlphaImageLoader(src='/project/www/img/sunflower_alpha_border.png',sizingMethod='crop');
                '/(?<![a-zA-Z\d])((?i:src)=\')'  . self::$_normalize_links_re_uri_prefix . '([^\']*\')/sxSX',
            );
            $value = preg_replace($patterns, '$1' . self::$_normalize_links['path_replace'] . '$2', $value);
        }
		*/
		return $m[1] . $m[2] . '="' . htmlspecialchars($value) . '"';
	}

	/**
	 *
	 * @param   array       $url
	 * @param   bool        &$is_fragment_only
	 * @return  array|bool  returns FALSE if error occured
	 */
	private static function _normalize_links_parse($url, &$is_fragment_only = false)
	{
		static $servbyname = array(); #speed improve for getservbyname()

		if ($url === false) return false;  #parse_url('#') возвращает пустой массив!

		#переводим схему и хост в нижний регистр, убираем точку в конце хоста
		if (array_key_exists('scheme', $url)) $url['scheme'] = strtolower($url['scheme']);
		if (array_key_exists('host', $url))   $url['host']   = strtolower(trim($url['host'], '.'));

		#меняем одни хосты на другие
		if (self::$_normalize_links['host_trans']
			&& array_key_exists('host', $url)
			&& array_key_exists($url['host'], self::$_normalize_links['host_trans']))
		{
			$url['host'] = self::$_normalize_links['host_trans'][ $url['host'] ];
		}

		$is_current_host = URL::is_current_host($url);
		$is_fragment_only = ($is_current_host
							 && array_key_exists('fragment', $url)
							 && ! array_key_exists('path', $url)
							 && ! array_key_exists('query', $url)) || $url === array();

		#дописываем или удаляем текущий хост с портом
		if (self::$_normalize_links['is_add_host'] && ! $is_fragment_only)
		{
			#дописываем текущий хост, если нужно
			list($scheme, ) = explode('/', strtolower($_SERVER['SERVER_PROTOCOL']));
			$url += array(
				'scheme' => $scheme,
				'host'   => strtolower($_SERVER['HTTP_HOST']),
			);

			#дописываем порт, только если он нестандартный
			if (! array_key_exists('port', $url))
			{
				#getservbyname() тормозит в PHP/5.2.11, ускоряем работу через кэширование в статическую переменную $servbyname
				$servbyname[$scheme] = $port = array_key_exists($scheme, $servbyname) ? $servbyname[$scheme] : getservbyname($scheme, 'tcp');
				if ($port !== false && $port !== intval($_SERVER['SERVER_PORT'])) $url['port'] = $port;
			}
		}

		$is_host_only = array_key_exists('scheme', $url)
						&& array_key_exists('host', $url)
						&& ! array_key_exists('path', $url)
						&& ! array_key_exists('query', $url)
						&& ! array_key_exists('fragment', $url);
		if ($is_host_only) $url['path'] = '/';

		if (array_key_exists('path', $url))
		{
			if ($url['path']{0} !== '/') return false;  #все пути должны начинаться с корня
			if ( $is_current_host
				 && self::$_normalize_links['path_search']
				 && self::$_normalize_links['path_replace']
				 && strpos($url['path'], self::$_normalize_links['path_search']) === 0
			)
			{
				$url['path'] = self::$_normalize_links['path_replace'] . substr($url['path'], strlen(self::$_normalize_links['path_search']));
			}
		}

		if (! self::$_normalize_links['is_add_host'] && $is_current_host) unset($url['scheme'], $url['host'], $url['port']);

		#parse_url() делает достаточно слабую проверку, проверяем URL как следует
		if ($url && ! URL::check($url)) return false;

		return $url;
	}

	/**
	 * Возвращает массив атрибутов, которым присваивается URI
	 * Массив вычисляется из self::$url_tags
	 *
	 * @return array
	 */
	private static function _url_attrs()
	{
		static $a = array();
		if (! $a) $a = array_unique(explode('|', implode('|', self::$url_tags)));
		return $a;
	}

	/**
	 * Заменяет домашнюю директорию на абсолютный путь.
	 *
	 * Пример вхождений, которые будут обработаны:
	 *		#замена внутри HTML
	 *		<a href="~/path/to/file.ext">
	 *		<a href='~/path/to/file.ext'>
	 *		<a href="~:admin/path/to/file.ext">
	 *
	 *		#замена внутри CSS
	 *		url(~/path/to/file.ext)
	 *
	 *		#замена внутри JS
	 *		<script type="text/javascript">
	 *		<!--//<![CDATA[
	 *			var hosts = {
	 *				'admin'  : '~:admin',
	 *				'static' : '~:static',
	 *				'media'  : '~:media'
	 *			};
	 *		//]]>-->
	 *		</script>
	 *
	 * Для сравнения неуклюжий синтаксис Smarty:
	 *		<a href="{'/path/to/file.ext'|project}">
	 *		<a href="{'/path/to/file.ext'|project:'admin'}">
	 *
	 *		{literal}
	 *		<script type="text/javascript">
	 *		<!--//<![CDATA[
	 *			var hosts = {
	 *				'admin'  : '{/literal}{''|project:'admin'}{literal}',
	 *				'static' : '{/literal}{''|project:'static'}{literal}',
	 *				'media'  : '{/literal}{''|project:'media'}{literal}'
	 *			};
	 *		//]]>-->
	 *		</script>
	 *		{/literal}
	 *
	 * @param   string       $s
	 * @param   int|null     $replace_count  Кол-во произведённых замен
	 * @return  string|bool                  Returns FALSE if error occured
	 */
	public static function replace_home_path($s, &$replace_count = 0)
	{
		if (! ReflectionTypeHint::isValid()) return false;
		return preg_replace_callback('
			~	(?<=	(["\'])	#1
					|	\(
				)

				#путь должен начинаться с либо с корня (только абсолютные пути), либо с ключа поддомена
				\~	\~?+	(?>		/
								|	:([a-zA-Z]+[a-zA-Z\d]*) #2 subdomain
							)

				#([^"\'\)\x00-\x20\x7f-\xff]*+)    #3
				#(?=(?(1) \\1 | \) ))

				((?(1)	[^"\'\x00-\x20\x7f-\xff]*+   (?= \\1 )
					|	[^"\'\)\x00-\x20\x7f-\xff]*+ (?= \)  )
				)) #3
			~sxSX', array('self', '_replace_home_path'), $s, -1, $replace_count);
	}

	private static function _replace_home_path(array $m)
	{
		//d($m);
		if ($m[0]{1} === '~') return substr($m[0], 1);  #обработка квотирования
		$url = '/' . $m[3];
		if (isset($m[2]) &&	array_key_exists($m[2], self::$_subdomains_map)) $url = self::$_subdomains_map[$m[2]] . $url;
		//d($url);
		#DEPRECATED, т. к. контекст (html, js, css) не известен
		#return self::src(htmlspecialchars_decode($url));
		return self::src($url, false);
	}

	/**
	 * Оптимизатор HTML/XML кода
	 */
	public static function optimize($s, $is_js = false, $is_css = false)
	{
		return Text_Optimize::html($s, $is_js, $is_css);
	}

	/**
	 * Convert all HTML entities to native UTF-8 characters
	 * Функция декодирует гораздо больше именованных сущностей, чем стандартная html_entity_decode()
	 * Все dec и hex сущности так же переводятся в UTF-8.
	 */
	public static function entity_decode($s, $is_special_chars = false)
	{
		return UTF8::html_entity_decode($s, $is_special_chars);
	}

	/**
	 * Convert special UTF-8 characters to HTML entities.
	 * Функция кодирует гораздо больше именованных сущностей, чем стандартная htmlentities()
	 */
	public static function entity_encode($s, $is_special_chars_only = false)
	{
		return UTF8::html_entity_encode($s, $is_special_chars_only);
	}

	/**
	 * Замена <nobr>...</nobr> на <span style="white-space: nowrap">...</span>
	 * Используется перед выводом HTML в браузер!
	 *
	 * @param   string  $s
	 * @return  string
	 */
	public static function nobr($s)
	{
		$s = str_replace('<nobr>', '<span style="white-space:nowrap">', $s);
		$s = str_replace('</nobr>', '</span>', $s);
		return $s;
	}

	/**
	 * Рассматривает входящую строку как предложение
	 * и оборачивает "висячие" слова в <nobr>...</nobr>.
	 * Кодировка символов -- UTF8.
	 *
	 * Примеры:
	 *   "Медведев обещает увеличить число офицеров в ВС РФ" -> "Медведев обещает увеличить число офицеров <nobr>в ВС РФ</nobr>"
	 *   "Общественный транспорт «доедет» до ГЛОНАССа к концу года" -> "Общественный транспорт «доедет» до ГЛОНАССа <nobr>к концу года</nobr>"
	 *   "«Роснефть» не пускает ТНК-ВР в «арктический альянс» с ВР" -> "«Роснефть» не пускает ТНК-ВР в «арктический <nobr>альянс» с ВР</nobr>"
	 *   "«Мосэнергосбыт» увеличил поставки электроэнергии на 4,5%" -> "«Мосэнергосбыт» увеличил поставки электроэнергии <nobr>на 4,5%</nobr>"
	 *
	 * @param   string     $s          Текст
	 * @param   int|digit  $chars_max  Максимальное кол-во символов для "висячих" слов.
	 *                                 Значение по-умолчанию явл. оптимальным и подобрано экспериментально.
	 * @param   string     $open_tag   Открывающий тэг
	 * @param   string     $close_tag  Закрывающий тэг
	 * @return  string
	 */
	public static function words_unhang($s, $chars_max = 6, $open_tag = '<nobr>', $close_tag = '</nobr>')
	{
		$s = preg_replace('~((?<=\s)\pL{1,2}+\s)?+			  #приклеиваем 1-2 буквенные слова, например предлоги и союзы: в, с, на, от, и
							[^\s;,\.!\?)%'	. "\xc2\xa0"      #&nbsp; [ ]
											. "\xc2\xbb"      #&raquo; [»]
											. "\xe2\x80\xa6"  #&hellip; […]
											. "\xc2\xae"      #&reg; [®]
											. ']*+
							.{1,' . $chars_max . '}+
							[;\.!\?)%'	. "\xc2\xbb"      #&raquo; [»]
										. "\xe2\x80\xa6"  #&hellip; […]
										. "\xc2\xae"      #&reg; [®]
										. ']*+
							$~usxSX', $open_tag . '$0' . $close_tag, $s);
		return $s;
	}

	/**
	 * Оптимизации, связанные с тэгом <noindex>.
	 * Используется перед выводом HTML в браузер!
	 *
	 * TODO
	 * Тэг <noindex> поддерживает только Яндекс, Гугл и др. не понимают.
	 * Подумать, возможно универсальным решением будет вывод текста через JS:
	 *		<script type="text/javascript">
	 *		<!--//<![CDATA[
	 *			document.write('текст, который не должен проиндексироваться поисковиками')
	 *		//]]>-->
	 *		</script>
	 *
	 * @link  http://help.yandex.ru/webmaster/?id=1111858  Тег <noindex>
	 *
	 * @param   string  $s
	 * @return  string
	 */
	public static function noindex($s)
	{
		#вырезаем лишние тэги, тэг <noindex> не может быть вложен друг в друга
		$invalid_tags = $deleted_tags = array();
		$s = self::normalize_tags($s, $invalid_tags, $deleted_tags, array('noindex'));

		#вырезаем лишние тэги <noindex></noindex> или </noindex><noindex>
		$s = preg_replace('~</?+noindex>
							([\x00-\x20\x7f]*+) #1
							</?+noindex>
						   ~sxiSX', '$1', $s);

		$s = str_replace('<noindex>', '<!--noindex-->', $s);
		$s = str_replace('</noindex>', '<!--/noindex-->', $s);
		return $s;
	}

}