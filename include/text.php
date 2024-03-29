<?php
/**
 * @file include/text.php
 */

use Zotlabs\Lib as Zlib;

use Michelf\MarkdownExtra;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\Exception\UnableToBuildUuidException;

use Zotlabs\Lib\Crypto;
use Zotlabs\Lib\SvgSanitizer;
use Zotlabs\Lib\Libzot;
use Zotlabs\Lib\AccessList;

require_once("include/bbcode.php");

// random string, there are 86 characters max in text mode, 128 for hex
// output is urlsafe

define('RANDOM_STRING_HEX',  0x00 );
define('RANDOM_STRING_TEXT', 0x01 );

/**
 * @brief This is our template processor.
 *
 * @param string SmartyEngine $s the string requiring macro substitution,
 *   or an instance of SmartyEngine
 * @param array $r key value pairs (search => replace)
 *
 * @return string substituted string
 */
function replace_macros($s, $r) {
	$arr = [
			'template' => $s,
			'params' => $r
	];

	/**
	 * @hooks replace_macros
	 *   * \e string \b template
	 *   * \e array \b params
	 */
	call_hooks('replace_macros', $arr);

	$t = App::template_engine();

	try {
		$output = $t->replace_macros($arr['template'], $arr['params']);
	} catch (Exception $e) {
		logger('Unable to render template: ' . $e->getMessage());
		$output = '<h3>ERROR: there was an error creating the output.</h3>';
	}

	return $output;
}

/**
 * @brief Generates a random string.
 *
 * @param number $size
 * @param int $type
 *
 * @return string
 */
function random_string($size = 64, $type = RANDOM_STRING_HEX) {
	// generate a bit of entropy and run it through the whirlpool
	$s = hash('whirlpool', (string) rand() . uniqid(rand(),true) . (string) rand(),(($type == RANDOM_STRING_TEXT) ? true : false));
	$s = (($type == RANDOM_STRING_TEXT) ? str_replace("\n","",base64url_encode($s,true)) : $s);

	return(substr($s, 0, $size));
}

/**
 * @brief This is our primary input filter.
 *
 * The high bit hack only involved some old IE browser, forget which (IE5/Mac?)
 * that had an XSS attack vector due to stripping the high-bit on an 8-bit character
 * after cleansing, and angle chars with the high bit set could get through as markup.
 *
 * This is now disabled because it was interfering with some legitimate unicode sequences
 * and hopefully there aren't a lot of those browsers left.
 *
 * Use this on any text input where angle chars are not valid or permitted
 * They will be replaced with safer brackets. This may be filtered further
 * if these are not allowed either.
 *
 * @param string $string Input string
 *
 * @return string Filtered string
 */
function notags($string) {

	return(str_replace(array("<",">"), array('[',']'), $string));

//  High-bit filter no longer used
//	return(str_replace(array("<",">","\xBA","\xBC","\xBE"), array('[',']','','',''), $string));
}


/**
 * use this on "body" or "content" input where angle chars shouldn't be removed,
 * and allow them to be safely displayed.
 *
 * @param string $string
 *
 * @return string
 */
function escape_tags($string) {
	if (!$string) {
		return EMPTY_STR;
	}
	return (htmlspecialchars($string, ENT_COMPAT, 'UTF-8', false));
}

/**
 * Escape URL's so they're safe for use in HTML and in HTML element attributes.
 */
function escape_url($input) {
  if (empty($input)) {
    return EMPTY_STR;
  }

  // This is a bit crude but seems to do the trick for now. It makes no
  // guarantees that the URL is valid for use after escaping.
  return htmlspecialchars($input, ENT_HTML5 | ENT_QUOTES);
}

function z_input_filter($s,$type = 'text/bbcode',$allow_code = false) {

	if($type === 'text/bbcode')
		return escape_tags($s);
	if($type == 'text/plain')
		return escape_tags($s);
	if($type == 'application/x-pdl')
		return escape_tags($s);

	if(App::$is_sys) {
		return $s;
	}

	if($allow_code) {
		if($type === 'text/markdown')
			return htmlspecialchars($s,ENT_QUOTES);
		return $s;
	}

	if($type === 'text/markdown') {
		$x = new Zlib\MarkdownSoap($s);
		return $x->clean();
	}

	if($type === 'text/html')
		return purify_html($s);

	return escape_tags($s);
}


/**
 * @brief Use HTMLPurifier to get standards compliant HTML.
 *
 * Use the <a href="http://htmlpurifier.org/" target="_blank">HTMLPurifier</a>
 * library to get filtered and standards compliant HTML.
 *
 * @see HTMLPurifier
 *
 * @param string $s raw HTML
 * @param boolean $allow_position allow CSS position
 * @return string standards compliant filtered HTML
 */
function purify_html($s, $allow_position = false) {

/**
 * @FIXME this function has html output, not bbcode - so safely purify these
 * require_once('include/html2bbcode.php');
 * $s = html2bb_video($s);
 * $s = oembed_html2bbcode($s);
 */

	$config = HTMLPurifier_Config::createDefault();
	$config->set('Cache.DefinitionImpl', null);
	$config->set('Attr.EnableID', true);

	// If enabled, target=blank attributes are added to all links.
	//$config->set('HTML.TargetBlank', true);
	//$config->set('Attr.AllowedFrameTargets', ['_blank', '_self', '_parent', '_top']);
	// restore old behavior of HTMLPurifier < 4.8, only used when targets allowed at all
	// do not add rel="noreferrer" to all links with target attributes
	//$config->set('HTML.TargetNoreferrer', false);
	// do not add noopener rel attributes to links which have a target attribute associated with them
	//$config->set('HTML.TargetNoopener', false);

	//Allow some custom data- attributes used by built-in libs.
	//In this way members which do not have allowcode set can still use the built-in js libs in webpages to some extent.

	$def = $config->getHTMLDefinition(true);

	//data- attributes used by the foundation library

	// f6 navigation

	//dropdown menu
	$def->info_global_attr['data-dropdown-menu'] = new HTMLPurifier_AttrDef_Text;
	//drilldown menu
	$def->info_global_attr['data-drilldown'] = new HTMLPurifier_AttrDef_Text;
	//accordion menu
	$def->info_global_attr['data-accordion-menu'] = new HTMLPurifier_AttrDef_Text;
	//responsive navigation
	$def->info_global_attr['data-responsive-menu'] = new HTMLPurifier_AttrDef_Text;
	$def->info_global_attr['data-responsive-toggle'] = new HTMLPurifier_AttrDef_Text;
	//magellan
	$def->info_global_attr['data-magellan'] = new HTMLPurifier_AttrDef_Text;
	$def->info_global_attr['data-magellan-target'] = new HTMLPurifier_AttrDef_Text;

	// f6 containers

	//accordion
	$def->info_global_attr['data-accordion'] = new HTMLPurifier_AttrDef_Text;
	$def->info_global_attr['data-accordion-item'] = new HTMLPurifier_AttrDef_Text;
	$def->info_global_attr['data-tab-content'] = new HTMLPurifier_AttrDef_Text;
	//dropdown
	$def->info_global_attr['data-dropdown'] = new HTMLPurifier_AttrDef_Text;
	//off-canvas
	$def->info_global_attr['data-off-canvas-wrapper'] = new HTMLPurifier_AttrDef_Text;
	$def->info_global_attr['data-off-canvas'] = new HTMLPurifier_AttrDef_Text;
	$def->info_global_attr['data-off-canvas-content'] = new HTMLPurifier_AttrDef_Text;
	//reveal
	$def->info_global_attr['data-reveal'] = new HTMLPurifier_AttrDef_Text;
	//tabs
	$def->info_global_attr['data-tabs'] = new HTMLPurifier_AttrDef_Text;
	$def->info_global_attr['data-tabs-content'] = new HTMLPurifier_AttrDef_Text;

	// f6 media

	//orbit
	$def->info_global_attr['data-orbit'] = new HTMLPurifier_AttrDef_Text;
	$def->info_global_attr['data-slide'] = new HTMLPurifier_AttrDef_Text;
	//tooltip
	$def->info_global_attr['data-tooltip'] = new HTMLPurifier_AttrDef_Text;

	// f6 plugins

	//abide - the use is pointless since we can't do anything with forms

	//equalizer
	$def->info_global_attr['data-equalizer'] = new HTMLPurifier_AttrDef_Text;
	$def->info_global_attr['data-equalizer-watch'] = new HTMLPurifier_AttrDef_Text;

	//interchange - potentially dangerous since it can load content

	//toggler
	$def->info_global_attr['data-toggler'] = new HTMLPurifier_AttrDef_Text;

	//sticky
	$def->info_global_attr['data-sticky'] = new HTMLPurifier_AttrDef_Text;
	$def->info_global_attr['data-sticky-container'] = new HTMLPurifier_AttrDef_Text;

	// f6 common

	$def->info_global_attr['data-options'] = new HTMLPurifier_AttrDef_Text;
	$def->info_global_attr['data-toggle'] = new HTMLPurifier_AttrDef_Text;
	$def->info_global_attr['data-close'] = new HTMLPurifier_AttrDef_Text;
	$def->info_global_attr['data-open'] = new HTMLPurifier_AttrDef_Text;
	$def->info_global_attr['data-position'] = new HTMLPurifier_AttrDef_Text;


	//data- attributes used by the bootstrap library
	$def->info_global_attr['data-dismiss'] = new HTMLPurifier_AttrDef_Text;
	$def->info_global_attr['data-target'] = new HTMLPurifier_AttrDef_Text;
	$def->info_global_attr['data-toggle'] = new HTMLPurifier_AttrDef_Text;
	$def->info_global_attr['data-backdrop'] = new HTMLPurifier_AttrDef_Text;
	$def->info_global_attr['data-keyboard'] = new HTMLPurifier_AttrDef_Text;
	$def->info_global_attr['data-show'] = new HTMLPurifier_AttrDef_Text;
	$def->info_global_attr['data-spy'] = new HTMLPurifier_AttrDef_Text;
	$def->info_global_attr['data-offset'] = new HTMLPurifier_AttrDef_Text;
	$def->info_global_attr['data-animation'] = new HTMLPurifier_AttrDef_Text;
	$def->info_global_attr['data-container'] = new HTMLPurifier_AttrDef_Text;
	$def->info_global_attr['data-delay'] = new HTMLPurifier_AttrDef_Text;
	$def->info_global_attr['data-placement'] = new HTMLPurifier_AttrDef_Text;
	$def->info_global_attr['data-title'] = new HTMLPurifier_AttrDef_Text;
	$def->info_global_attr['data-trigger'] = new HTMLPurifier_AttrDef_Text;
	$def->info_global_attr['data-content'] = new HTMLPurifier_AttrDef_Text;
	$def->info_global_attr['data-trigger'] = new HTMLPurifier_AttrDef_Text;
	$def->info_global_attr['data-parent'] = new HTMLPurifier_AttrDef_Text;
	$def->info_global_attr['data-ride'] = new HTMLPurifier_AttrDef_Text;
	$def->info_global_attr['data-slide-to'] = new HTMLPurifier_AttrDef_Text;
	$def->info_global_attr['data-slide'] = new HTMLPurifier_AttrDef_Text;
	$def->info_global_attr['data-interval'] = new HTMLPurifier_AttrDef_Text;
	$def->info_global_attr['data-pause'] = new HTMLPurifier_AttrDef_Text;
	$def->info_global_attr['data-wrap'] = new HTMLPurifier_AttrDef_Text;
	$def->info_global_attr['data-offset-top'] = new HTMLPurifier_AttrDef_Text;
	$def->info_global_attr['data-offset-bottom'] = new HTMLPurifier_AttrDef_Text;

	//some html5 elements
	//Block
	$def->addElement('section', 'Block', 'Flow', 'Common');
	$def->addElement('nav',     'Block', 'Flow', 'Common');
	$def->addElement('article', 'Block', 'Flow', 'Common');
	$def->addElement('aside',   'Block', 'Flow', 'Common');
	$def->addElement('header',  'Block', 'Flow', 'Common');
	$def->addElement('footer',  'Block', 'Flow', 'Common');
	//Inline
	$def->addElement('button',  'Inline', 'Inline', 'Common');
	$def->addElement('mark',  'Inline', 'Inline', 'Common');


	if($allow_position) {
		$cssDefinition = $config->getCSSDefinition();

		$cssDefinition->info['position'] = new HTMLPurifier_AttrDef_Enum(array('absolute', 'fixed', 'relative', 'static', 'inherit'), false);

		$cssDefinition->info['left'] = new HTMLPurifier_AttrDef_CSS_Composite(array(
			new HTMLPurifier_AttrDef_CSS_Length(),
			new HTMLPurifier_AttrDef_CSS_Percentage()
		));

		$cssDefinition->info['right'] = new HTMLPurifier_AttrDef_CSS_Composite(array(
			new HTMLPurifier_AttrDef_CSS_Length(),
			new HTMLPurifier_AttrDef_CSS_Percentage()
		));

		$cssDefinition->info['top'] = new HTMLPurifier_AttrDef_CSS_Composite(array(
			new HTMLPurifier_AttrDef_CSS_Length(),
			new HTMLPurifier_AttrDef_CSS_Percentage()
		));

		$cssDefinition->info['bottom'] = new HTMLPurifier_AttrDef_CSS_Composite(array(
			new HTMLPurifier_AttrDef_CSS_Length(),
			new HTMLPurifier_AttrDef_CSS_Percentage()
		));
	}

	$purifier = new HTMLPurifier($config);

	return $purifier->purify($s);
}


/**
 * @brief Generate a string that's random, but usually pronounceable.
 *
 * Used to generate initial passwords.
 *
 * @note In order to create "pronounceable" strings some consonant pairs or
 * letters that does not make a very good word ending are chopped off, so that
 * the returned string length can be lower than $len.
 *
 * @param int $len max length of generated string
 * @return string Genereated random, but usually pronounceable string
 */
function autoname($len) {

	if ($len <= 0)
		return '';

	$vowels = array('a','a','ai','au','e','e','e','ee','ea','i','ie','o','ou','u');
	if (mt_rand(0, 5) == 4)
		$vowels[] = 'y';

	$cons = array(
			'b','bl','br',
			'c','ch','cl','cr',
			'd','dr',
			'f','fl','fr',
			'g','gh','gl','gr',
			'h',
			'j',
			'k','kh','kl','kr',
			'l',
			'm',
			'n',
			'p','ph','pl','pr',
			'qu',
			'r','rh',
			's','sc','sh','sm','sp','st',
			't','th','tr',
			'v',
			'w','wh',
			'x',
			'z','zh'
			);

	$midcons = array('ck','ct','gn','ld','lf','lm','lt','mb','mm', 'mn','mp',
				'nd','ng','nk','nt','rn','rp','rt');

	// avoid these consonant pairs at the end of the string
	$noend = array('bl', 'br', 'cl','cr','dr','fl','fr','gl','gr',
				'kh', 'kl','kr','mn','pl','pr','rh','tr','qu','wh');

	$start = mt_rand(0, 2);
	if ($start == 0)
		$table = $vowels;
	else
		$table = $cons;

	$word = '';

	for ($x = 0; $x < $len; $x ++) {
		$r = mt_rand(0, count($table) - 1);
		$word .= $table[$r];

		if ($table == $vowels)
			$table = array_merge($cons, $midcons);
		else
			$table = $vowels;
	}

	$word = substr($word, 0, $len);

	foreach ($noend as $noe) {
		if ((strlen($word) > 2) && (substr($word, -2) == $noe)) {
			$word = substr($word, 0, -1);
			break;
		}
	}
	// avoid the letter 'q' as it does not make a very good word ending
	if (substr($word, -1) == 'q')
		$word = substr($word, 0, -1);

	return $word;
}


/**
 * @brief escape text ($str) for XML transport
 *
 * @param string $str
 * @return string Escaped text.
 */
function xmlify($str) {

	//$buffer = '';

	if (!$str)
		return EMPTY_STR;

	if(is_array($str)) {

		// allow to fall through so we ge a PHP error, as the log statement will
		// probably get lost in the noise unless we're specifically looking for it.

		btlogger('xmlify called with array: ' . print_r($str,true), LOGGER_NORMAL, LOG_WARNING);
	}
/*
	$len = mb_strlen($str);
	for($x = 0; $x < $len; $x ++) {
		$char = mb_substr($str,$x,1);

		switch( $char ) {
			case "\r" :
				break;
			case "&" :
				$buffer .= '&amp;';
				break;
			case "'" :
				$buffer .= '&apos;';
				break;
			case "\"" :
				$buffer .= '&quot;';
				break;
			case '<' :
				$buffer .= '&lt;';
				break;
			case '>' :
				$buffer .= '&gt;';
				break;
			case "\n" :
				$buffer .= "\n";
				break;
			default :
				$buffer .= $char;
				break;
		}
	}
	$buffer = trim($buffer);

	return($buffer);
*/
	$buffer = htmlspecialchars($str, ENT_QUOTES, "UTF-8");
	$buffer = trim($buffer);
	return $buffer;

}

/**
 * @brief Undo an xmlify.
 *
 * Pass xml escaped text ($s), returns unescaped text.
 *
 * @param string $s
 *
 * @return string
 */
function unxmlify($s) {
/*
	$ret = str_replace('&amp;', '&', $s);
	$ret = str_replace(array('&lt;', '&gt;', '&quot;', '&apos;'), array('<', '>', '"', "'"), $ret);

	return $ret;
*/

	if (!$s)
		return EMPTY_STR;

	if(is_array($s)) {

		// allow to fall through so we ge a PHP error, as the log statement will
		// probably get lost in the noise unless we're specifically looking for it.

		btlogger('unxmlify called with array: ' . print_r($s,true), LOGGER_NORMAL, LOG_WARNING);
	}

	$ret = htmlspecialchars_decode($s, ENT_QUOTES);
	return $ret;
}

/**
 * @brief Automatic pagination.
 *
 * To use, get the count of total items.
 * Then call App::set_pager_total($number_items);
 * Optionally call App::set_pager_itemspage($n) to the number of items to display on each page
 * Then call paginate($a) after the end of the display loop to insert the pager block on the page
 * (assuming there are enough items to paginate).
 * When using with SQL, the setting LIMIT %d, %d => App::$pager['start'],App::$pager['itemspage']
 * will limit the results to the correct items for the current page.
 * The actual page handling is then accomplished at the application layer.
 *
 */
function paginate() {
	$o = '';
	$stripped = preg_replace('/(&page=[0-9]*)/','',App::$query_string);

//	$stripped = preg_replace('/&zid=(.*?)([\?&]|$)/ism','',$stripped);

	$stripped = str_replace('q=','',$stripped);
	$stripped = trim($stripped,'/');
	$pagenum = App::$pager['page'];
	$url = z_root() . '/' . $stripped;

	if(App::$pager['total'] > App::$pager['itemspage']) {
		$o .= '<div class="pager">';
		if(App::$pager['page'] != 1)
			$o .= '<span class="pager_prev">'."<a href=\"$url".'&page='.(App::$pager['page'] - 1).'">' . t('prev') . '</a></span> ';

		$o .=  "<span class=\"pager_first\"><a href=\"$url"."&page=1\">" . t('first') . "</a></span> ";

		$numpages = App::$pager['total'] / App::$pager['itemspage'];

		$numstart = 1;
		$numstop = $numpages;

		if($numpages > 14) {
			$numstart = (($pagenum > 7) ? ($pagenum - 7) : 1);
			$numstop = (($pagenum > ($numpages - 7)) ? $numpages : ($numstart + 14));
		}

		for($i = $numstart; $i <= $numstop; $i++){
			if($i == App::$pager['page'])
				$o .= '<span class="pager_current">'.(($i < 10) ? '&nbsp;'.$i : $i);
			else
				$o .= "<span class=\"pager_n\"><a href=\"$url"."&page=$i\">".(($i < 10) ? '&nbsp;'.$i : $i)."</a>";
			$o .= '</span> ';
		}

		if((App::$pager['total'] % App::$pager['itemspage']) != 0) {
			if($i == App::$pager['page'])
				$o .= '<span class="pager_current">'.(($i < 10) ? '&nbsp;'.$i : $i);
			else
				$o .= "<span class=\"pager_n\"><a href=\"$url"."&page=$i\">".(($i < 10) ? '&nbsp;'.$i : $i)."</a>";
			$o .= '</span> ';
		}

		$lastpage = (($numpages > intval($numpages)) ? intval($numpages)+1 : $numpages);
		$o .= "<span class=\"pager_last\"><a href=\"$url"."&page=$lastpage\">" . t('last') . "</a></span> ";

		if((App::$pager['total'] - (App::$pager['itemspage'] * App::$pager['page'])) > 0)
			$o .= '<span class="pager_next">'."<a href=\"$url"."&page=".(App::$pager['page'] + 1).'">' . t('next') . '</a></span>';
		$o .= '</div>'."\r\n";
	}

	return $o;
}

/**
 * @brief
 *
 * @param int $i
 * @param string $more
 * @param string $less
 * @return string Parsed HTML from template 'alt_pager.tpl'
 */
function alt_pager($i, $more = '', $less = '') {

	if(! $more)
		$more = t('older');
	if(! $less)
		$less = t('newer');

	$url = z_root() . '/' . drop_query_params(App::$query_string, ['page', 'q']);

	return replace_macros(get_markup_template('alt_pager.tpl'), array(
		'$has_less' => ((App::$pager['page'] > 1) ? true : false),
		'$has_more' => (($i > 0 && $i >= App::$pager['itemspage']) ? true : false),
		'$less' => $less,
		'$more' => $more,
		'$url' => $url,
		'$url_appendix' => ((strpos($url, '?')) ? '&' : '?'),
		'$prevpage' => App::$pager['page'] - 1,
		'$nextpage' => App::$pager['page'] + 1,
	));

}


/**
 * @brief Generate a guaranteed unique (for this domain) item ID for ATOM.
 *
 * Safe from birthday paradox.
 *
 * @return string a unique id
 */
function item_message_id() {

	return new_uuid();

}

/**
 * @brief Generate a guaranteed unique photo ID.
 *
 * Safe from birthday paradox.
 *
 * @return string a uniqe hash
 */
function photo_new_resource() {

	return new_uuid();

}

/**
 * @brief
 *
 * for html,xml parsing - let's say you've got
 * an attribute foobar="class1 class2 class3"
 * and you want to find out if it contains 'class3'.
 * you can't use a normal sub string search because you
 * might match 'notclass3' and a regex to do the job is
 * possible but a bit complicated.
 *
 * pass the attribute string as $attr and the attribute you
 * are looking for as $s - returns true if found, otherwise false
 *
 * @param string $attr attribute string
 * @param string $s attribute you are looking for
 * @return boolean true if found
 */
function attribute_contains($attr, $s) {
	// remove quotes
	$attr = str_replace([ '"',"'" ],['',''],$attr);
	$a = explode(' ', $attr);
	if($a && in_array($s, $a))
		return true;

	return false;
}

/**
 * @brief Log to syslog
 *
 * @param string $msg Message to log
 * @param int $priority - compatible with syslog
 */
function hz_syslog($msg, $priority = LOG_INFO) {
	openlog("hz-log", LOG_PID | LOG_PERROR, LOG_LOCAL0);
	syslog($priority, $msg);
	closelog();
}

/**
 * @brief like hz_syslog() but with a function backtrace to pinpoint certain classes
 * of problems which show up deep in the calling stack.
 *
 * @param string $msg Message to log
 * @param int $priority - compatible with syslog
 */
function bt_syslog($msg, $priority = LOG_INFO) {
	$stack = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
	if($stack) {
		for($x = 1; $x < count($stack); $x ++) {
			$s = 'stack: ' . basename($stack[$x]['file']) . ':' . $stack[$x]['line'] . ':' . $stack[$x]['function'] . '()';
			openlog("bt-log", LOG_PID | LOG_PERROR, LOG_LOCAL0);
			syslog($priority, $s);
			closelog();
		}
	}
}



/**
 * @brief Logging function for Hubzilla.
 *
 * Logging output is configured through Hubzilla's system config. The log file
 * is set in system logfile, log level in system loglevel and to enable logging
 * set system debugging.
 *
 * Available constants for log level are LOGGER_NORMAL, LOGGER_TRACE, LOGGER_DEBUG,
 * LOGGER_DATA and LOGGER_ALL.
 *
 * Since PHP5.4 we get the file, function and line automatically where the logger
 * was called, so no need to add it to the message anymore.
 *
 * @param string $msg Message to log
 * @param int $level A log level
 * @param int $priority - compatible with syslog
 */
function logger($msg, $level = LOGGER_NORMAL, $priority = LOG_INFO) {

	if(App::$module == 'setup' && is_writable('install.log')) {
		$debugging = true;
		$logfile = 'install.log';
		$loglevel = LOGGER_ALL;
	}
	else {
		$debugging = get_config('system', 'debugging');
		$loglevel  = intval(get_config('system', 'loglevel'));
		$logfile   = get_config('system', 'logfile');
	}

	if((! $debugging) || (! $logfile) || ($level > $loglevel))
		return;

	$where = '';

	$stack = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
	$where = basename($stack[0]['file']) . ':' . $stack[0]['line'] . ':' . $stack[1]['function'] . ': ';

	$s = datetime_convert('UTC','UTC', 'now', ATOM_TIME) . ':' . log_priority_str($priority) . ':' . logid() . ':' . $where . $msg . PHP_EOL;
	$pluginfo = array('filename' => $logfile, 'loglevel' => $level, 'message' => $s,'priority' => $priority, 'logged' => false);

	if(! (App::$module == 'setup'))
		call_hooks('logger',$pluginfo);

	if(! $pluginfo['logged'])
		@file_put_contents($pluginfo['filename'], $pluginfo['message'], FILE_APPEND);
}

function logid() {
	$x = session_id();
	if(! $x)
		$x = getmypid();
	return substr(hash('whirlpool',$x),0,10);
}

/**
 * @brief like logger() but with a function backtrace to pinpoint certain classes
 * of problems which show up deep in the calling stack.
 *
 * @param string $msg Message to log
 * @param int $level A log level
 * @param int $priority - compatible with syslog
 */
function btlogger($msg, $level = LOGGER_NORMAL, $priority = LOG_INFO) {

	if(! defined('BTLOGGER_DEBUG_FILE'))
		define('BTLOGGER_DEBUG_FILE','btlogger.out');

	logger($msg, $level, $priority);

	if(file_exists(BTLOGGER_DEBUG_FILE) && is_writable(BTLOGGER_DEBUG_FILE)) {
		$stack = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
		$where = basename($stack[0]['file']) . ':' . $stack[0]['line'] . ':' . $stack[1]['function'] . ': ';
		$s = datetime_convert('UTC','UTC', 'now', ATOM_TIME) . ':' . log_priority_str($priority) . ':' . logid() . ':' . $where . $msg . PHP_EOL;
		@file_put_contents(BTLOGGER_DEBUG_FILE, $s, FILE_APPEND);
	}

	$stack = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
	if($stack) {
		for($x = 1; $x < count($stack); $x ++) {
			$s = 'stack: ' . basename($stack[$x]['file']) . ':' . $stack[$x]['line'] . ':' . $stack[$x]['function'] . '()';
			logger($s,$level, $priority);
			if(file_exists(BTLOGGER_DEBUG_FILE) && is_writable(BTLOGGER_DEBUG_FILE)) {
				@file_put_contents(BTLOGGER_DEBUG_FILE, $s . PHP_EOL, FILE_APPEND);
			}
		}
	}
}



function log_priority_str($priority) {
	$parr = array(
		LOG_EMERG   => 'LOG_EMERG',
		LOG_ALERT   => 'LOG_ALERT',
		LOG_CRIT    => 'LOG_CRIT',
		LOG_ERR     => 'LOG_ERR',
		LOG_WARNING => 'LOG_WARNING',
		LOG_NOTICE  => 'LOG_NOTICE',
		LOG_INFO    => 'LOG_INFO',
		LOG_DEBUG   => 'LOG_DEBUG'
	);

	if($parr[$priority])
		return $parr[$priority];
	return 'LOG_UNDEFINED';
}

/**
 * @brief This is a special logging facility for developers.
 *
 * It allows one to target specific things to trace/debug and is identical to
 * logger() with the exception of the log filename. This allows one to isolate
 * specific calls while allowing logger() to paint a bigger picture of overall
 * activity and capture more detail.
 *
 * If you find dlogger() calls in checked in code, you are free to remove them -
 * so as to provide a noise-free development environment which responds to events
 * you are targetting personally.
 *
 * @param string $msg Message to log
 * @param int $level A log level.
 */
function dlogger($msg, $level = 0) {

	// turn off logger in install mode

	if(App::$module == 'setup')
		return;

	$debugging = get_config('system','debugging');
	$loglevel  = intval(get_config('system','loglevel'));
	$logfile   = get_config('system','dlogfile');

	if((! $debugging) || (! $logfile) || ($level > $loglevel))
		return;

	$where = '';

	$stack = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
	$where = basename($stack[0]['file']) . ':' . $stack[0]['line'] . ':' . $stack[1]['function'] . ': ';


	@file_put_contents($logfile, datetime_convert('UTC','UTC', 'now', ATOM_TIME) . ':' . logid() . ' ' . $where . $msg . PHP_EOL, FILE_APPEND);
}


function profiler($t1,$t2,$label) {
	if(file_exists('profiler.out') && $t1 && t2)
		@file_put_contents('profiler.out', sprintf('%01.4f %s',$t2 - $t1,$label) . PHP_EOL, FILE_APPEND);
}


function activity_match($haystack,$needle) {

	if(! is_array($needle))
		$needle = [ $needle ];

	if($needle) {
		foreach($needle as $n) {
			if(($haystack === $n) || (strtolower(basename((string)$n)) === strtolower(basename((string)$haystack)))) {
				return true;
			}
		}
	}
	return false;
}

/**
 * @brief Pull out all \#hashtags and \@person tags from $s.
 *
 * We also get \@person\@domain.com - which would make
 * the regex quite complicated as tags can also
 * end a sentence. So we'll run through our results
 * and strip the period from any tags which end with one.
 *
 * @param string $s
 * @return array Returns an array of tags found, or empty array.
 */
function get_tags($s) {
	$ret = array();
	$match = array();

	// ignore anything in a code or svg block
	$s = preg_replace('/\[code(.*?)\](.*?)\[\/code\]/sm','',$s);
	$s = preg_replace('/\[nobb\](.*?)\[\/nobb\]/sm','',$s);
	$s = preg_replace('/\[noparse\](.*?)\[\/noparse\]/sm','',$s);
	$s = preg_replace('/\[svg(.*?)\](.*?)\[\/svg\]/sm','',$s);
	$s = preg_replace('/\[toc(.*?)\]/sm','',$s);

	// ignore anything in [style= ]
	$s = preg_replace('/\[style=(.*?)\]/sm','',$s);


	// ignore anything in [color= ], because it may contain color codes which are mistaken for tags
	$s = preg_replace('/\[color=(.*?)\]/sm','',$s);

	// match any double quoted tags
	if(preg_match_all('/([@#\!]\&quot\;.*?\&quot\;)/',$s,$match)) {
		foreach($match[1] as $mtch) {
			$ret[] = $mtch;
		}
	}


	if(preg_match_all('/([@#\!]\".*?\")/',$s,$match)) {
		foreach($match[1] as $mtch) {
			$ret[] = $mtch;
		}
	}

	// match bracket mentions
	if(preg_match_all('/([@!]\!?\{.*?\})/',$s,$match)) {
		foreach($match[1] as $mtch) {
			$ret[] = $mtch;
		}
	}

	// Pull out single word tags. These can be @nickname, @first_last
	// and #hash tags.
	if(preg_match_all('/(?<![a-zA-Z0-9=\pL\/\?\;])([@#\!]\!?[^ \x0D\x0A,;:\?\[\{\&]+)/u',$s,$match)) {
		foreach($match[1] as $mtch) {

			// Cleanup/ignore false positives

			// Just ignore these rather than try and adjust the regex to deal with them
			if(in_array($mtch,[ '@!', '!!' ]))
				continue;
			// likewise for trailing period. Strip it off rather than complicate the regex further.
			if(substr($mtch,-1,1) === '.')
				$mtch = substr($mtch,0,-1);
			// ignore strictly numeric tags like #1 or #^ bookmarks or ## double hash
			if((strpos($mtch,'#') === 0) && ( ctype_digit(substr($mtch,1)) || in_array(substr($mtch,1,1), [ '^', '#' ])))
				continue;
			// or quote remnants from the quoted strings we already picked out earlier
			if(strpos($mtch,'&quot'))
				continue;
			if(strpos($mtch,'"'))
				continue;

			$ret[] = $mtch;
		}
	}

	// bookmarks
	if(preg_match_all('/#\^\[(url|zrl)(.*?)\](.*?)\[\/(url|zrl)\]/',$s,$match,PREG_SET_ORDER)) {
		foreach($match as $mtch) {
			$ret[] = $mtch[0];
		}
	}

	// make sure the longer tags are returned first so that if two or more have common substrings
	// we'll replace the longest ones first. Otherwise the common substring would be found in
	// both strings and the string replacement would link both to the shorter strings and
	// fail to link the longer string. Hubzilla github issue #378

	usort($ret,'tag_sort_length');

	//	logger('get_tags: ' . print_r($ret,true));

	return $ret;
}

function tag_sort_length($a,$b) {
	if(mb_strlen($a) == mb_strlen($b))
		return 0;

	return((mb_strlen($b) < mb_strlen($a)) ? (-1) : 1);
}

function total_sort($a,$b) {
	if($a['total'] == $b['total'])
		return 0;

	return(($b['total'] > $a['total']) ? 1 : (-1));
}


/**
 * @brief Quick and dirty quoted_printable encoding.
 *
 * @param string $s
 * @return string
 */
function qp($s) {
	return str_replace ("%", "=", rawurlencode($s));
}


function get_mentions($item,$tags) {
	$o = '';

	if(! count($tags))
		return $o;

	foreach($tags as $x) {
		if($x['ttype'] == TERM_MENTION) {
			$o .= "\t\t" . '<link rel="mentioned" href="' . $x['url'] . '" />' . "\r\n";
			$o .= "\t\t" . '<link rel="ostatus:attention" href="' . $x['url'] . '" />' . "\r\n";
		}
	}
	return $o;
}


function contact_block() {
	$o = '';

	if(! App::$profile['uid'])
		return;

	if(! perm_is_allowed(App::$profile['uid'],get_observer_hash(),'view_contacts'))
		return;

	$shown = get_pconfig(App::$profile['uid'],'system','display_friend_count');

	if($shown === false)
		$shown = 36;
	if($shown == 0)
		return;

	$is_owner = ((local_channel() && local_channel() == App::$profile['uid']) ? true : false);
	$sql_extra = '';

	$abook_flags = " and abook_pending = 0 and abook_self = 0 and abook_blocked = 0 and abook_ignored = 0 ";

	if(! $is_owner) {
		$abook_flags .= " and abook_hidden = 0 ";
		$sql_extra = " and xchan_hidden = 0 ";
	}

	if((! is_array(App::$profile)) || (App::$profile['hide_friends']))
		return $o;

	$r = q("SELECT COUNT(abook_id) AS total FROM abook left join xchan on abook_xchan = xchan_hash WHERE abook_channel = %d
		$abook_flags and xchan_orphan = 0 and xchan_deleted = 0 $sql_extra",
		intval(App::$profile['uid'])
	);

	if(count($r)) {
		$total = intval($r[0]['total']);
	}

	if(! $total) {
		return $o;
	}

	$randfunc = db_getfunc('RAND');

	$r = q("SELECT abook.*, xchan.* FROM abook left join xchan on abook.abook_xchan = xchan.xchan_hash WHERE abook_channel = %d $abook_flags and abook_archived = 0 and xchan_orphan = 0 and xchan_deleted = 0 $sql_extra ORDER BY $randfunc LIMIT %d",
		intval(App::$profile['uid']),
		intval($shown)
	);

	if(! $r) {
		return $o;
	}

	$contacts = t('Connections');
	$micropro = [];
	foreach($r as $rr) {

		// There is no setting to discover if you are bi-directionally connected
		// Use the ability to post comments as an indication that this relationship is more
		// than wishful thinking; even though soapbox channels and feeds will disable it.
		$rr['perminfo']['connpermcount']=0;
		$rr['perminfo']['connperms']=t('Accepts').': ';
		if(intval(get_abconfig(App::$profile['uid'],$rr['xchan_hash'],'their_perms','post_comments'))) {
			$rr['perminfo']['connpermcount']++;
			$rr['perminfo']['connperms'] .= t('Comments');
		}
		if(intval(get_abconfig(App::$profile['uid'],$rr['xchan_hash'],'their_perms','send_stream'))) {
			$rr['perminfo']['connpermcount']++;
			$rr['perminfo']['connperms'] = ($rr['perminfo']['connperms']) ? $rr['perminfo']['connperms'] . ', ' : $rr['perminfo']['connperms'] ;
			$rr['perminfo']['connperms'] .= t('Stream items');
		}
		if(intval(get_abconfig(App::$profile['uid'],$rr['xchan_hash'],'their_perms','post_wall'))) {
			$rr['perminfo']['connpermcount']++;
			$rr['perminfo']['connperms'] = ($rr['perminfo']['connperms']) ? $rr['perminfo']['connperms'] . ', ' : $rr['perminfo']['connperms'] ;
			$rr['perminfo']['connperms'] .= t('Wall posts');
		}

		if ($rr['perminfo']['connpermcount'] == 0) {
			$rr['perminfo']['connperms'] .= t('Nothing');
		}

		if(!$is_owner && $rr['perminfo']['connpermcount'] !== 0)
			unset($rr['perminfo']);

		$micropro[] = micropro($rr,true,'mpfriend');
	}

	$tpl = get_markup_template('contact_block.tpl');
	$o = replace_macros($tpl, array(
		'$contacts' => $contacts,
		'$nickname' => App::$profile['channel_address'],
		'$viewconnections' => (($total > $shown) ? sprintf(t('View all %s connections'),$total) : ''),
		'$micropro' => $micropro
	));

	$arr = ['contacts' => $r, 'output' => $o];
	/**
	 * @hooks contact_block_end
	 *   Called at the end of contact_block(), but can not manipulate the output.
	 *   * \e array \b contacts - Result array from database
	 *   * \e string \b output - the generated output
	 */
	call_hooks('contact_block_end', $arr);

	return $o;
}


function chanlink_hash($s) {
	return z_root() . '/chanview?f=&hash=' . urlencode($s);
}

function chanlink_url($s) {
	return z_root() . '/chanview?f=&url=' . urlencode($s);
}


function chanlink_cid($d) {
	return z_root() . '/chanview?f=&cid=' . intval($d);
}

function magiclink_url($observer,$myaddr,$url) {
	return (($observer)
		? z_root() . '/magic?f=&owa=1&bdest=' . bin2hex($url) . '&addr=' . $myaddr
		: $url
	);
}



function micropro($contact, $redirect = false, $class = '', $mode = false) {

	if(x($contact,'click'))
		$url = '#';
	else
		$url = chanlink_hash($contact['xchan_hash']);


	$tpl = 'micropro_img.tpl';
	if($mode === true)
		$tpl = 'micropro_txt.tpl';
	if($mode === 'card')
		$tpl = 'micropro_card.tpl';

	return replace_macros(get_markup_template($tpl), array(
		'$click' => (x($contact,'click') ? $contact['click'] : ''),
		'$class' => $class . (x($contact,'archived') && $contact['archived'] ? ' archived' : ''),
		'$oneway' => (x($contact,'oneway') && $contact['oneway'] ? true : false),
		'$perminfo' => (x($contact,'perminfo') ? $contact['perminfo'] : ''),
		'$url' => $url,
		'$photo' => $contact['xchan_photo_s'],
		'$name' => $contact['xchan_name'],
		'$addr' => $contact['xchan_addr'],
		'$title' => $contact['xchan_name'] . ' [' . $contact['xchan_addr'] . ']',
		'$network' => sprintf(t('Network: %s'), $contact['xchan_network'])
	));
}


function search($s,$id='search-box',$url='/search',$save = false) {

	return replace_macros(get_markup_template('searchbox.tpl'),array(
		'$s' => $s,
		'$id' => $id,
		'$action_url' => z_root() . $url,
		'$search_label' => t('Search'),
		'$save_label' => t('Save'),
		'$savedsearch' => feature_enabled(local_channel(),'savedsearch')
	));
}


function searchbox($s,$id='search-box',$url='/search',$save = false) {
	return replace_macros(get_markup_template('searchbox.tpl'),array(
		'$s' => $s,
		'$id' => $id,
		'$action_url' => z_root() . '/' . $url,
		'$search_label' => t('Search'),
		'$save_label' => t('Save'),
		'$savedsearch' => ($save && feature_enabled(local_channel(),'savedsearch'))
	));
}

/**
 * @brief Replace naked text hyperlink with HTML formatted hyperlink.
 *
 * @param string $s
 * @param boolean $me (optional) default false
 * @return string
 */
function linkify($s, $me = false) {
	$s = preg_replace("/(https?\:\/\/[a-zA-Z0-9\pL\:\/\-\?\&\;\.\=\_\@\~\#\'\%\$\!\+\,\@]*)/u", (($me) ? ' <a href="$1" rel="me nofollow" >$1</a>' : ' <a href="$1" >$1</a>'), $s);
	$s = preg_replace("/\<(.*?)(src|href)=(.*?)\&amp\;(.*?)\>/ism",'<$1$2=$3&$4>',$s);

	return($s);
}

/**
 * @brief Replace media element using http url with https to a local redirector
 *  if using https locally.
 *
 * Looks for HTML tags containing src elements that are http when we're viewing an https page
 * Typically this throws an insecure content violation in the browser. So we redirect them
 * to a local redirector which uses https and which redirects to the selected content
 *
 * @param string $s
 * @returns string
 */
function sslify($s) {

	// Local photo cache
	$str = [
		'body' => $s,
		'uid' => local_channel()
	];
	/**
	 * @hooks cache_body_hook
	 *   * \e string \b body The content to parse and also the return value
	 *   * \e int|bool \b uid
	 */
	call_hooks('cache_body_hook', $str);

	$s = $str['body'];

	if (strpos(z_root(),'https:') === false)
		return $s;

	// By default we'll only sslify img tags because media files will probably choke.
	// You can set sslify_everything if you want - but it will likely white-screen if it hits your php memory limit.
	// The downside is that http: media files will likely be blocked by your browser
	// Complain to your browser maker

	$allow = get_config('system','sslify_everything');
	$pattern = (($allow) ? "/\<(.*?)src=[\"|'](http\:.*?)[\"|'](.*?)\>/" : "/\<img(.*?)src=[\"|'](http\:.*?)[\"|'](.*?)\>/" );

	$matches = null;
	$cnt = preg_match_all($pattern, $s, $matches, PREG_SET_ORDER);
	if ($cnt) {
		foreach ($matches as $match) {
			$filename = basename( parse_url($match[2], PHP_URL_PATH) );
			$s = str_replace($match[2],z_root() . '/sslify/' . $filename . '?f=&url=' . urlencode($match[2]),$s);
		}
	}

	return $s;
}

/**
 * @brief Get an array of poke verbs.
 *
 * @return array
 *  * \e index is present tense verb
 *  * \e value is array containing past tense verb, translation of present, translation of past
 */
function get_poke_verbs() {

	$arr = [
		'poke' => ['poked', t('poke'), t('poked')],
		'ping' => ['pinged', t('ping'), t('pinged')],

		// Those might be better suited for a nsfw poke addon

		// 'prod' => ['prodded', t('prod'), t('prodded')],
		// 'slap' => ['slapped', t('slap'), t('slapped')],
		// 'finger' => ['fingered', t('finger'), t('fingered')],
		// 'rebuff' => ['rebuffed', t('rebuff'), t('rebuffed')]
	];

	/**
	 * @hooks poke_verbs
	 *   * \e array associative array with another array as value
	 */
	call_hooks('poke_verbs', $arr);

	return $arr;
}

/**
 * @brief Get an array of mood verbs.
 *
 * @return array
 *   * \e index is the verb
 *   * \e value is the translated verb
 */
function get_mood_verbs() {

	$arr = [
		'happy'      => t('happy'),
		'sad'        => t('sad'),
		'mellow'     => t('mellow'),
		'tired'      => t('tired'),
		'perky'      => t('perky'),
		'angry'      => t('angry'),
		'stupefied'  => t('stupefied'),
		'puzzled'    => t('puzzled'),
		'interested' => t('interested'),
		'bitter'     => t('bitter'),
		'cheerful'   => t('cheerful'),
		'alive'      => t('alive'),
		'annoyed'    => t('annoyed'),
		'anxious'    => t('anxious'),
		'cranky'     => t('cranky'),
		'disturbed'  => t('disturbed'),
		'frustrated' => t('frustrated'),
		'depressed'  => t('depressed'),
		'motivated'  => t('motivated'),
		'relaxed'    => t('relaxed'),
		'surprised'  => t('surprised'),
	];

	/**
	 * @hooks mood_verbs
	 *   * \e array associative array with mood verbs
	 */
	call_hooks('mood_verbs', $arr);

	return $arr;
}

/**
 * @brief Function to list all smilies, both internal and from addons.
 *
 * @param boolean $default_only (optional) default false
 *   true will prevent that plugins can add smilies
 * @return array Returns an associative array with:
 *   * \e array \b texts
 *   * \e array \b icons
 */
function list_smilies($default_only = false) {

	$texts =  array(
		'&lt;3',
		'&lt;/3',
		':-)',
		';-)',
		':-(',
		':-P',
		':-p',
		':-"',
		':-&quot;',
		':-x',
		':-X',
		':-D',
		'8-|',
		'8-O',
		':-O',
		'\\o/',
		'o.O',
		'O.o',
		'o_O',
		'O_o',
		":'(",
		":-!",
		":-/",
		":-[",
		"8-)",
		':beer',
		':homebrew',
		':coffee',
		':facepalm',
		':like',
		':dislike'
	);

	$icons = array(
		'<img class="smiley" src="' . z_root() . '/images/emoticons/smiley-heart.gif" alt="&lt;3" />',
		'<img class="smiley" src="' . z_root() . '/images/emoticons/smiley-brokenheart.gif" alt="&lt;/3" />',
		'<img class="smiley" src="' . z_root() . '/images/emoticons/smiley-smile.gif" alt=":-)" />',
		'<img class="smiley" src="' . z_root() . '/images/emoticons/smiley-wink.gif" alt=";-)" />',
		'<img class="smiley" src="' . z_root() . '/images/emoticons/smiley-frown.gif" alt=":-(" />',
		'<img class="smiley" src="' . z_root() . '/images/emoticons/smiley-tongue-out.gif" alt=":-P" />',
		'<img class="smiley" src="' . z_root() . '/images/emoticons/smiley-tongue-out.gif" alt=":-p" />',
		'<img class="smiley" src="' . z_root() . '/images/emoticons/smiley-kiss.gif" alt=":-\"" />',
		'<img class="smiley" src="' . z_root() . '/images/emoticons/smiley-kiss.gif" alt=":-\"" />',
		'<img class="smiley" src="' . z_root() . '/images/emoticons/smiley-kiss.gif" alt=":-x" />',
		'<img class="smiley" src="' . z_root() . '/images/emoticons/smiley-kiss.gif" alt=":-X" />',
		'<img class="smiley" src="' . z_root() . '/images/emoticons/smiley-laughing.gif" alt=":-D" />',
		'<img class="smiley" src="' . z_root() . '/images/emoticons/smiley-surprised.gif" alt="8-|" />',
		'<img class="smiley" src="' . z_root() . '/images/emoticons/smiley-surprised.gif" alt="8-O" />',
		'<img class="smiley" src="' . z_root() . '/images/emoticons/smiley-surprised.gif" alt=":-O" />',
		'<img class="smiley" src="' . z_root() . '/images/emoticons/smiley-thumbsup.gif" alt="\\o/" />',
		'<img class="smiley" src="' . z_root() . '/images/emoticons/smiley-Oo.gif" alt="o.O" />',
		'<img class="smiley" src="' . z_root() . '/images/emoticons/smiley-Oo.gif" alt="O.o" />',
		'<img class="smiley" src="' . z_root() . '/images/emoticons/smiley-Oo.gif" alt="o_O" />',
		'<img class="smiley" src="' . z_root() . '/images/emoticons/smiley-Oo.gif" alt="O_o" />',
		'<img class="smiley" src="' . z_root() . '/images/emoticons/smiley-cry.gif" alt=":\'(" />',
		'<img class="smiley" src="' . z_root() . '/images/emoticons/smiley-foot-in-mouth.gif" alt=":-!" />',
		'<img class="smiley" src="' . z_root() . '/images/emoticons/smiley-undecided.gif" alt=":-/" />',
		'<img class="smiley" src="' . z_root() . '/images/emoticons/smiley-embarassed.gif" alt=":-[" />',
		'<img class="smiley" src="' . z_root() . '/images/emoticons/smiley-cool.gif" alt="8-)" />',
		'<img class="smiley" src="' . z_root() . '/images/emoticons/beer_mug.gif" alt=":beer" />',
		'<img class="smiley" src="' . z_root() . '/images/emoticons/beer_mug.gif" alt=":homebrew" />',
		'<img class="smiley" src="' . z_root() . '/images/emoticons/coffee.gif" alt=":coffee" />',
		'<img class="smiley" src="' . z_root() . '/images/emoticons/smiley-facepalm.gif" alt=":facepalm" />',
		'<img class="smiley" src="' . z_root() . '/images/emoticons/like.gif" alt=":like" />',
		'<img class="smiley" src="' . z_root() . '/images/emoticons/dislike.gif" alt=":dislike" />'

	);

	$params = array('texts' => $texts, 'icons' => $icons);

	if($default_only)
		return $params;

	/**
	 * @hooks smile
	 *   * \e array \b texts - default values and also return value
	 *   * \e array \b icons - default values and also return value
	 */
	call_hooks('smilie', $params);

	return $params;
}

/**
 * @brief Replaces text emoticons with graphical images.
 *
 * It is expected that this function will be called using HTML text.
 * We will escape text between HTML pre and code blocks, and HTML attributes
 * (such as urls) from being processed.
 *
 * At a higher level, the bbcode [nosmile] tag can be used to prevent this
 * function from being executed by the prepare_text() routine when preparing
 * bbcode source for HTML display.
 *
 * @param string $s
 * @param boolean $sample (optional) default false
 * @return string
 */
function smilies($s, $sample = false) {

	if(intval(get_config('system', 'no_smilies'))
		|| (local_channel() && intval(get_pconfig(local_channel(), 'system', 'no_smilies'))))
		return $s;

	$s = preg_replace_callback('{<(pre|code)>.*?</\1>}ism', 'smile_shield', $s);
	$s = preg_replace_callback('/<[a-z]+ .*?>/ism', 'smile_shield', $s);


	$params = list_smilies();
	$params['string'] = $s;

	if ($sample) {
		$s = '<div class="smiley-sample">';
		for ($x = 0; $x < count($params['texts']); $x ++) {
			$s .= '<dl><dt>' . $params['texts'][$x] . '</dt><dd>' . $params['icons'][$x] . '</dd></dl>';
		}
	} else {
		$params['string'] = preg_replace_callback('/&lt;(3+)/','preg_heart',$params['string']);
		$s = str_replace($params['texts'],$params['icons'],$params['string']);
	}


	$s = preg_replace_callback('/<!--base64:(.*?)-->/ism', 'smile_unshield', $s);

	return $s;
}

/**
 * @brief
 *
 * @param array $m
 * @return string
 */
function smile_shield($m) {
	return '<!--base64:' . base64special_encode($m[0]) . '-->';
}

function smile_unshield($m) {
	return base64special_decode($m[1]);
}

/**
 * @brief Expand <3333 to the correct number of hearts.
 *
 * @param array $x
 */
function preg_heart($x) {

	if (strlen($x[1]) == 1)
		return $x[0];

	$t = '';
	for($cnt = 0; $cnt < strlen($x[1]); $cnt ++)
		$t .= '<img class="smiley" src="' . z_root() . '/images/emoticons/smiley-heart.gif" alt="&lt;3" />';

	$r =  str_replace($x[0],$t,$x[0]);

	return $r;
}



function day_translate($s) {
	$ret = str_replace(array('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'),
		array( t('Monday'), t('Tuesday'), t('Wednesday'), t('Thursday'), t('Friday'), t('Saturday'), t('Sunday')),
		$s);

	$ret = str_replace(array('January','February','March','April','May','June','July','August','September','October','November','December'),
		array( t('January'), t('February'), t('March'), t('April'), t('May'), t('June'), t('July'), t('August'), t('September'), t('October'), t('November'), t('December')),
		$ret);

	return $ret;
}

/**
 * @brief normalises a string.
 *
 * @param string $url
 * @return string
 */
function normalise_link($url) {
	if (!$url) {
		return EMPTY_STR;
	}

	$ret = str_replace(array('https:', '//www.'), array('http:', '//'), $url);

	return(rtrim($ret, '/'));
}

/**
 * @brief Compare two URLs to see if they are the same.
 *
 * But ignore slight but hopefully insignificant differences such as if one
 * is https and the other isn't, or if one is www.something and the other
 * isn't - and also ignore case differences.
 *
 * @see normalise_link()
 *
 * @param string $a
 * @param string $b
 * @return true if the URLs match, otherwise false
 */
function link_compare($a, $b) {
	if (strcasecmp(normalise_link($a), normalise_link($b)) === 0)
		return true;

	return false;
}

function theme_attachments(&$item) {

	$s = '';
	$arr = json_decode($item['attach'],true);

	if(is_array($arr) && count($arr)) {

		$attaches = [];
		foreach($arr as $r) {

			if(isset($r['type']))
				$icon = getIconFromType($r['type']);

			$label = '';

			if(isset($r['name'])) {
				$label = urldecode(htmlspecialchars($r['name'], ENT_COMPAT, 'UTF-8'));
			}

			if(! $label && isset($r['title'])) {
				$label = urldecode(htmlspecialchars($r['title'], ENT_COMPAT, 'UTF-8'));
			}

			if(!$label && isset($r['href'])) {
				$m = parse_url($r['href']);
				if ($m && $m['path']) {
					$label = basename($m['path']);
				}
			}

			//some feeds provide an attachment where title an empty space
			if(!trim($label)) {
				$label = t('Unknown attachment');
			}

			$title = t('Size') . ' ' . (isset($r['length']) ? userReadableSize($r['length']) : t('unknown'));

			$revision = $r['revision'] ?? '';

			require_once('include/channel.php');

			if (isset($r['href'])) {
				if(is_foreigner($item['author_xchan']))
					$url = $r['href'];
				else
					$url = z_root() . '/magic?f=&owa=1&hash=' . $item['author_xchan'] . '&bdest=' . bin2hex($r['href'] . '/' . $revision);
			}

			//$s .= '<a href="' . $url . '" title="' . $title . '" class="attachlink"  >' . $icon . '</a>';
			if (isset($label) && isset($url) && isset($icon) && isset($title))
				$attaches[] = array('label' => $label, 'url' => $url, 'icon' => $icon, 'title' => $title);
		}

		if (count($attaches) > 0)
			$s = replace_macros(get_markup_template('item_attach.tpl'), [
				'$attaches' => $attaches
			]);
	}

	return $s;
}

function format_categories(&$item,$writeable) {
	$s = '';

	$terms = isset($item['term']) ? get_terms_oftype($item['term'], TERM_CATEGORY) : [];
	if($terms) {
		$categories = array();
		foreach($terms as $t) {
			$term = htmlspecialchars($t['term'],ENT_COMPAT,'UTF-8',false) ;
			if(! trim($term))
				continue;
			$removelink = (($writeable) ?  z_root() . '/filerm/' . $item['id'] . '?f=&cat=' . urlencode($t['term']) : '');
			$categories[] = array('term' => $term, 'writeable' => $writeable, 'removelink' => $removelink, 'url' => zid($t['url']));
		}

		$s = replace_macros(get_markup_template('item_categories.tpl'),array(
			'$remove' => t('remove category'),
			'$categories' => $categories
		));
	}

	return $s;
}

/**
 * @brief Add any hashtags which weren't mentioned in the message body, e.g. community tags
 *
 * @param[in] array &$item
 * @return string HTML link of hashtag
 */

function format_hashtags(&$item) {

	$s = '';
	$terms = isset($item['term']) ? get_terms_oftype($item['term'], array(TERM_HASHTAG, TERM_COMMUNITYTAG)) : [];

	if($terms) {
		foreach($terms as $t) {
			$term = htmlspecialchars($t['term'], ENT_COMPAT, 'UTF-8', false) ;
			if(! trim($term))
				continue;
			if(empty($t['url']))
				continue;
			if(strpos($item['body'], $t['url']) || stripos($item['body'], '#' . $t['term']))
				continue;
			if($s)
				$s .= ' ';

			$s .= '<span class="badge rounded-pill bg-info"><i class="fa fa-hashtag"></i>&nbsp;<a class="text-white" href="' . zid($t['url']) . '" >' . $term . '</a></span>';
		}
	}

	return $s;
}


function format_mentions(&$item) {

	$s = '';
	$terms = isset($item['term']) ? get_terms_oftype($item['term'], TERM_MENTION) : [];
	if($terms) {
		foreach($terms as $t) {
			if(! isset($t['term']))
				continue;
			$term = htmlspecialchars($t['term'],ENT_COMPAT,'UTF-8',false) ;
			if(! trim($term))
				continue;
			if(strpos($item['body'], $t['url']))
				continue;
			if($s)
				$s .= ' ';
			$s .= '<span class="badge rounded-pill bg-success"><i class="fa fa-at"></i>&nbsp;<a class="text-white" href="' . zid($t['url']) . '" >' . $term . '</a></span>';
		}
	}

	return $s;
}


function format_filer(&$item) {
	$s = '';

	$terms = isset($item['term']) ? get_terms_oftype($item['term'], TERM_FILE) : [];
	if($terms) {
		$categories = array();
		foreach($terms as $t) {
			$term = htmlspecialchars($t['term'],ENT_COMPAT,'UTF-8',false) ;
			if(! trim($term))
				continue;
			$removelink = z_root() . '/filerm/' . $item['id'] . '?f=&term=' . urlencode($t['term']);
			$categories[] = array('term' => $term, 'removelink' => $removelink, 'id' => $item['id']);
		}

		$s = replace_macros(get_markup_template('item_filer.tpl'),array(
			'$remove' => t('remove from file'),
			'$categories' => $categories
		));
	}

	return $s;
}


function generate_map($coord) {
	$coord = trim($coord);
	$coord = str_replace(array(',','/','  '),array(' ',' ',' '),$coord);

	$arr = [
			'lat' => trim(substr($coord, 0, strpos($coord, ' '))),
			'lon' => trim(substr($coord, strpos($coord, ' ')+1)),
			'html' => ''
	];

	/**
	 * @hooks generate_map
	 *   * \e string \b lat
	 *   * \e string \b lon
	 *   * \e string \b html the parsed HTML to return
	 */
	call_hooks('generate_map', $arr);

	return (($arr['html']) ? $arr['html'] : $coord);
}

function generate_named_map($location) {
	$arr = [
			'location' => $location,
			'html' => ''
	];

	/**
	 * @hooks generate_named_map
	 *   * \e string \b location
	 *   * \e string \b html the parsed HTML to return
	 */
	call_hooks('generate_named_map', $arr);

	return (($arr['html']) ? $arr['html'] : $location);
}


function prepare_body(&$item,$attach = false,$opts = false) {

	/**
	 * @hooks prepare_body_init
	 *   * \e array \b item
	 */
	call_hooks('prepare_body_init', $item);

	$s = '';
	$photo = '';
	$is_photo = ((($item['verb'] === ACTIVITY_POST) && ($item['obj_type'] === ACTIVITY_OBJ_PHOTO)) ? true : false);

	if ($is_photo) {
		$object = json_decode($item['obj'],true);
		$ptr = null;
		if (is_array($object) && array_key_exists('url',$object) && is_array($object['url'])) {
			if (array_key_exists(0,$object['url'])) {
				foreach ($object['url'] as $link) {
					if(array_key_exists('width',$link) && $link['width'] >= 640 && $link['width'] <= 1024) {
						$ptr = $link;
					}
				}
				if (! $ptr) {
					$ptr = $object['url'][0];
				}
			}
			else {
				$ptr = $object['url'];
			}

			// if original photo width is > 640px make it a cover photo
			if ($ptr) {
				if (array_key_exists('width',$ptr) && $ptr['width'] > 640) {
				$photo = '<a href="' . zid(rawurldecode($object['id'])) . '" target="_blank" rel="nofollow noopener"><img style="max-width:' . $ptr['width'] . 'px; width:100%; height:auto;" src="' . zid(rawurldecode($ptr['href'])) . '"></a>';
				}
				else {
					$item['body'] = '[zmg]' . $ptr['href'] . '[/zmg]' . "\n\n" . $item['body'];
				}
			}
		}
	}

	if($item['item_obscured']) {
		$s .= prepare_binary($item);
	}
	else {
		if($item['summary']) {
			$s .= prepare_text('[summary]' . $item['summary'] . '[/summary]' . $item['body'],$item['mimetype'],$opts);
		}
		else {
			$s .= prepare_text($item['body'],$item['mimetype'], $opts);
		}
	}


	$poll = (($item['obj_type'] === 'Question' && in_array($item['verb'],[ ACTIVITY_POST, ACTIVITY_UPDATE, ACTIVITY_SHARE ])) ? format_poll($item, $s, $opts) : false);
	if ($poll) {
		$s = $poll;
	}

	$event = (in_array($item['obj_type'], ['Event', ACTIVITY_OBJ_EVENT]) ? format_event_obj($item['obj']) : []);

	$prep_arr = [
		'item' => $item,
		'html' => $event ? $event['content'] : $s,
		'event' => $event ? $event['header'] : '',
		'photo' => $photo
	];

	/**
	 * @hooks prepare_body
	 *   * \e array \b item
	 *   * \e string \b html - the parsed HTML to return
	 *   * \e string \b event - the event header to return
	 *   * \e string \b photo - the photo to return
	 */
	call_hooks('prepare_body', $prep_arr);

	$s = $prep_arr['html'];
	$photo = $prep_arr['photo'];
	$event = $prep_arr['event'];

	if(! $attach) {
		return $s;
	}

	if(strpos($s,'<div class="map">') !== false && $item['coord']) {
		$x = generate_map(trim($item['coord']));
		if($x) {
			$s = preg_replace('/\<div class\=\"map\"\>/','$0' . $x,$s);
		}
	}

	$attachments = theme_attachments($item);

	$writeable = ((get_observer_hash() == $item['owner_xchan']) ? true : false);

	$tags = format_hashtags($item);

	$mentions = '';
	if($item['resource_type'] == 'photo')
		$mentions = format_mentions($item);

	$categories = format_categories($item,$writeable);

	$filer = '';
	if(local_channel() == $item['uid'])
		$filer = format_filer($item);

	if($s)
		$s = sslify($s);

	if($photo)
		$photo = sslify($photo);

	if($event)
		$event = sslify($event);

	$prep_arr = array(
		'item' => $item,
		'photo' => $photo,
		'html' => $s,
		'event' => $event,
		'categories' => $categories,
		'folders' => $filer,
		'tags' => $tags,
		'mentions' => $mentions,
		'attachments' => $attachments
	);

	call_hooks('prepare_body_final', $prep_arr);

	unset($prep_arr['item']);

	return $prep_arr;
}


function prepare_binary($item) {
	return replace_macros(get_markup_template('item_binary.tpl'), [
		'$download'  => t('Download binary/encrypted content'),
		'$url'       => z_root() . '/viewsrc/' . $item['id'] . '/download'
	]);
}


function format_poll($item,$s,$opts) {

	if (! is_array($item['obj'])) {
		$act = json_decode($item['obj'],true);
	}
	else {
		$act = $item['obj'];
	}

	if (! is_array($act)) {
		return EMPTY_STR;
	}

	$closed = false;
	$commentable = can_comment_on_post(((local_channel()) ? get_observer_hash() : EMPTY_STR), $item);
	$activated = ((local_channel() && local_channel() == $item['uid'] && get_observer_hash() !== $item['owner_xchan']) ? true : false);
	$output = $s;

	if (strpos($item['body'], '[/share]') !== false) {
		$output = substr($output, 0, -12);
	}

	$output .= EOL . EOL;

	if ($act['type'] === 'Question') {
		if ($activated and $commentable) {
			$output .= '<form id="question-form-' . $item['id'] . '" >';
		}
		if (array_key_exists('anyOf',$act) && is_array($act['anyOf'])) {
			$totalResponses = 0;
			foreach ($act['anyOf'] as $poll) {
				if (array_path_exists('replies/totalItems',$poll)) {
					$totalResponses += intval($poll['replies']['totalItems']);
				}
			}

			foreach ($act['anyOf'] as $poll) {
				if (array_key_exists('name',$poll) && $poll['name']) {
					$text = html2plain(purify_html($poll['name']),256);
					if (array_path_exists('replies/totalItems',$poll)) {
						$total = $poll['replies']['totalItems'];
					}
					else {
						$total = 0;
					}
					if ($activated && $commentable) {
						//$output .= '<input type="checkbox" name="answer[]" value="' . htmlspecialchars($text) . '"> ' . $text . '</input>' . ' (' . $total . ')' . EOL;

						$output .= '<input type="checkbox" name="answer[]" value="' . htmlspecialchars($text) . '">&nbsp;&nbsp;<strong>' . $text . '</strong>' . EOL;
						$output .= '<div class="progress bg-secondary bg-opacity-25" style="height: 3px;">';
						$output .= '<div class="progress-bar bg-info" role="progressbar" style="width: ' . (($totalResponses) ?  round($total / $totalResponses * 100) : 0). '%;" aria-valuenow="" aria-valuemin="0" aria-valuemax="100"></div>';
						$output .= '</div>';
						$output .= '<div class="text-muted"><small>' . sprintf(tt('%d Vote', '%d Votes', $total, 'noun'), $total) . '&nbsp;|&nbsp;' . (($totalResponses) ? round($total / $totalResponses * 100) . '%' : '0%') . '</small></div>';
						$output .= EOL;
					}
					else {
						//$output .= '[ ] ' . $text . ' (' . $total . ')' . EOL;
						$output .= '<input type="checkbox" name="answer[]" value="' . htmlspecialchars($text) . '" disabled="disabled">&nbsp;&nbsp;<strong>' . $text . '</strong>' . EOL;
						$output .= '<div class="progress bg-secondary bg-opacity-25" style="height: 3px;">';
						$output .= '<div class="progress-bar bg-info" role="progressbar" style="width: ' . (($totalResponses) ?  round($total / $totalResponses * 100) : 0) . '%;" aria-valuenow="" aria-valuemin="0" aria-valuemax="100"></div>';
						$output .= '</div>';
						$output .= '<div class="text-muted"><small>' . sprintf(tt('%d Vote', '%d Votes', $total, 'noun'), $total) . '&nbsp;|&nbsp;' . (($totalResponses) ? round($total / $totalResponses * 100) . '%' : '0%') . '</small></div>';
						$output .= EOL;
					}
				}
			}
		}
		if (array_key_exists('oneOf',$act) && is_array($act['oneOf'])) {
			$totalResponses = 0;
			foreach ($act['oneOf'] as $poll) {
				if (array_path_exists('replies/totalItems',$poll)) {
					$totalResponses += intval($poll['replies']['totalItems']);
				}
			}
			foreach ($act['oneOf'] as $poll) {
				if (array_key_exists('name',$poll) && $poll['name']) {
					$text = html2plain(purify_html($poll['name']),256);
					if (array_path_exists('replies/totalItems',$poll)) {
						$total = $poll['replies']['totalItems'];
					}
					else {
						$total = 0;
					}
					if ($activated && $commentable) {
						$output .= '<input type="radio" name="answer" value="' . htmlspecialchars($text) . '">&nbsp;&nbsp;<strong>' . $text . '</strong>' . EOL;
						$output .= '<div class="progress bg-secondary bg-opacity-25" style="height: 3px;">';
						$output .= '<div class="progress-bar bg-info" role="progressbar" style="width: ' . (($totalResponses) ?  round($total / $totalResponses * 100) : 0). '%;" aria-valuenow="" aria-valuemin="0" aria-valuemax="100"></div>';
						$output .= '</div>';
						$output .= '<div class="text-muted"><small>' . sprintf(tt('%d Vote', '%d Votes', $total, 'noun'), $total) . '&nbsp;|&nbsp;' . (($totalResponses) ? round($total / $totalResponses * 100) . '%' : '0%') . '</small></div>';
						$output .= EOL;
					}

					else {
						$output .= '<input type="radio" name="answer" value="' . htmlspecialchars($text) . '" disabled="disabled">&nbsp;&nbsp;<strong>' . $text . '</strong>' . EOL;
						$output .= '<div class="progress bg-secondary bg-opacity-25" style="height: 3px;">';
						$output .= '<div class="progress-bar bg-info" role="progressbar" style="width: ' . (($totalResponses) ?  round($total / $totalResponses * 100) : 0) . '%;" aria-valuenow="" aria-valuemin="0" aria-valuemax="100"></div>';
						$output .= '</div>';
						$output .= '<div class="text-muted"><small>' . sprintf(tt('%d Vote', '%d Votes', $total, 'noun'), $total) . '&nbsp;|&nbsp;' . (($totalResponses) ? round($total / $totalResponses * 100) . '%' : '0%') . '</small></div>';
						$output .= EOL;
					}
				}
			}
		}

		$message = (($totalResponses) ? sprintf(tt('%d Vote in total', '%d Votes in total', $totalResponses, 'noun'), $totalResponses) . EOL : '');

		if ($item['comments_closed'] > NULL_DATE) {
			$t = datetime_convert('UTC',date_default_timezone_get(), $item['comments_closed'], 'Y-m-d H:i');
			$closed = ((datetime_convert() > $item['comments_closed']) ? true : false);
			if ($closed) {
				$message .= t('Poll has ended');
			}
			else {
				$message .= sprintf(t('Poll ends in %s'), '<span class="autotime" title="' . $t . '"></span>');
			}
		}

		$output .= '<div class="mb-3">' . $message . '</div>';

		if ($activated && $commentable && !$closed) {
			$output .= '<input type="button" class="btn btn-std btn-success" name="vote" value="' . t("Vote") . '" onclick="submitPoll(' . $item['id'] . '); return false;">'. '</form>';
		}

		if (strpos($item['body'], '[/share]') !== false) {
			$output .= '</div></div>';
		}
	}
	return $output;
}




/**
 * @brief Given a text string, convert from content_type to HTML.
 *
 * Take a text in plain text, html, markdown, bbcode, PDL or PHP and prepare
 * it to return HTML.
 *
 * In bbcode this function will add smilie icons.
 *
 * @param string $text
 * @param string $content_type (optional)
 *  default 'text/bbcode', other values are 'text/plain', 'text/html',
 *  'text/markdown', 'application/x-pdl', 'application/x-php'
 * @param boolean|array $opts (optional)
 *  default false, otherwise configuration array for bbcode()
 * @return string
 *  The parsed $text as prepared HTML.
 */
function prepare_text($text, $content_type = 'text/bbcode', $opts = false) {

	switch($content_type) {
		case 'text/plain':
			$s = escape_tags($text);
			break;

		case 'text/html':
			$s = $text;
			break;

		case 'text/markdown':
			$text = Zlib\MarkdownSoap::unescape($text);
			$s = MarkdownExtra::defaultTransform($text);
			break;

		case 'application/x-pdl';
			$s = escape_tags($text);
			break;

		// No security checking is done here at display time - so we need to verify
		// that the author is allowed to use PHP before storing. We also cannot allow
		// importation of PHP text bodies from other sites. Therefore this content
		// type is only valid for web pages (and profile details).

		// It may be possible to provide a PHP message body which is evaluated on the
		// sender's site before sending it elsewhere. In that case we will have a
		// different content-type here.

		case 'application/x-php':
			ob_start();
			eval($text);
			$s = ob_get_contents();
			ob_end_clean();
			break;

		case 'text/bbcode':
		case '':
		default:
			require_once('include/bbcode.php');

			if(stristr($text, '[nosmile]'))
				$s = bbcode($text, ((is_array($opts)) ? $opts : [] ));
			else
				$s = smilies(bbcode($text, ((is_array($opts)) ? $opts : [] )));

			$s = zidify_links($s);

			break;
	}

//logger('prepare_text: ' . $s);

	return $s;
}


function create_export_photo_body(&$item) {
	if(($item['verb'] === ACTIVITY_POST) && ($item['obj_type'] === ACTIVITY_OBJ_PHOTO)) {
		$j = json_decode($item['obj'],true);
		if($j) {
			$item['body'] .= "\n\n" . (($j['body']) ? $j['body'] : $j['bbcode']);
			$item['sig'] = '';
		}
	}
}

/**
 * @brief Return atom link elements for all of our hubs.
 *
 * @return string
 */
function feed_hublinks() {
	$hub = get_config('system', 'huburl');

	$hubxml = '';
	if(strlen($hub)) {
		$hubs = explode(',', $hub);
		if(count($hubs)) {
			foreach($hubs as $h) {
				$h = trim($h);
				if(! strlen($h))
					continue;

				$hubxml .= '<link rel="hub" href="' . xmlify($h) . '" />' . "\n" ;
			}
		}
	}

	return $hubxml;
}

/**
 * @brief Return atom link elements for salmon endpoints
 *
 * @param string $nick
 * @return string
 */
function feed_salmonlinks($nick) {

	$salmon  = '<link rel="salmon" href="' . xmlify(z_root() . '/salmon/' . $nick) . '" />' . "\n" ;

	// old style links that status.net still needed as of 12/2010

	$salmon .= '  <link rel="http://salmon-protocol.org/ns/salmon-replies" href="' . xmlify(z_root() . '/salmon/' . $nick) . '" />' . "\n" ;
	$salmon .= '  <link rel="http://salmon-protocol.org/ns/salmon-mention" href="' . xmlify(z_root() . '/salmon/' . $nick) . '" />' . "\n" ;

	return $salmon;
}


function get_plink($item,$conversation_mode = true) {
	if($conversation_mode)
		$key = 'plink';
	else
		$key = 'llink';

	$zidify = true;

	if(array_key_exists('author',$item) && $item['author']['xchan_network'] !== 'zot6')
		$zidify = false;

	if(x($item,$key)) {
		return array(
			'href' => (($zidify) ? zid($item[$key]) : $item[$key]),
			'title' => t('Link to Source'),
		);
	}
	else {
		return false;
	}
}


function unamp($s) {
	return str_replace('&amp;', '&', $s);
}

function layout_select($channel_id, $current = '') {
	$r = q("select mid, v from item left join iconfig on iconfig.iid = item.id
		where iconfig.cat = 'system' and iconfig.k = 'PDL' and item.uid = %d and item_type = %d ",
		intval($channel_id),
		intval(ITEM_TYPE_PDL)
	);

	$selected = '';
	$options = '';

	if($r) {
		$empty_selected = (($current === false) ? ' selected="selected" ' : '');
		$options .= '<option value="" ' . $empty_selected . '>' . t('default') . '</option>';
		foreach($r as $rr) {
			$selected = (($rr['mid'] == $current) ? ' selected="selected" ' : '');
			$options .= '<option value="' . $rr['mid'] . '"' . $selected . '>' . $rr['v'] . '</option>';
		}
	}

	$o = replace_macros(get_markup_template('field_select_raw.tpl'), array(
		'$field'	=> array('layout_mid', t('Page layout'), $selected, t('You can create your own with the layouts tool'), $options)
	));

	return $o;
}


function mimetype_select($channel_id, $current = 'text/bbcode', $choices = null, $element = 'mimetype') {

	$x = (($choices) ? $choices : [
		'text/bbcode'       => t('BBcode'),
		'text/html'         => t('HTML'),
		'text/markdown'     => t('Markdown'),
		'text/plain'        => t('Text'),
		'application/x-pdl' => t('Comanche Layout')
	]);


	if((App::$is_sys) || (channel_codeallowed($channel_id) && $channel_id == local_channel())){
		$x['application/x-php'] = t('PHP');
	}

	$selected = '';
	$options = '';

	foreach($x as $y => $z) {
		$selected = (($y == $current) ? ' selected="selected" ' : '');
		$options .= '<option value="' . $y . '"' . $selected . '>' . $z . '</option>';
	}

	$o = replace_macros(get_markup_template('field_select_raw.tpl'), array(
		'$field'	=> array( $element, t('Page content type'), $selected, '', $options)
	));

	return $o;
}

function engr_units_to_bytes ($size_str) {
	if(! $size_str)
		return $size_str;
	switch (substr(trim($size_str), -1)) {
		case 'M': case 'm': return (int)$size_str * 1048576;
		case 'K': case 'k': return (int)$size_str * 1024;
		case 'G': case 'g': return (int)$size_str * 1073741824;
		default: return $size_str;
	}
}


function base64url_encode($s, $strip_padding = true) {

	$s = strtr(base64_encode($s),'+/','-_');

	if($strip_padding)
		$s = str_replace('=','',$s);

	return $s;
}

function base64url_decode($s, $strict = false) {
	if(is_array($s)) {
		logger('base64url_decode: illegal input: ' . print_r(debug_backtrace(), true));
		return $s;
	}
	return base64_decode(strtr($s,'-_','+/'), $strict);
}


function base64special_encode($s, $strip_padding = true) {

	$s = strtr(base64_encode($s),'+/',',.');

	if($strip_padding)
		$s = str_replace('=','',$s);

	return $s;
}

function base64special_decode($s, $strict = false) {
	if(is_array($s)) {
		logger('base64url_decode: illegal input: ' . print_r(debug_backtrace(), true));
		return $s;
	}
	return base64_decode(strtr($s,',.','+/'), $strict);
}

/**
 * @brief Return a div to clear floats.
 *
 * @return string
 */
function cleardiv() {
	return '<div class="clear"></div>';
}


function bb_translate_video($s) {
	$arr = array('string' => $s);
	call_hooks('bb_translate_video',$arr);
	return $arr['string'];
}

function html2bb_video($s) {
	$arr = array('string' => $s);
	call_hooks('html2bb_video',$arr);
	return $arr['string'];
}

/**
 * apply xmlify() to all values of array $val, recursively
 */
function array_xmlify($val) {
	if (is_bool($val)) return $val?"true":"false";
	if (is_array($val)) return array_map('array_xmlify', $val);
	return xmlify((string) $val);
}


function reltoabs($text, $base) {
	if (empty($base))
		return $text;

	$base = rtrim($base,'/');

	$base2 = $base . "/";

	// Replace links
	$pattern = "/<a([^>]*) href=\"(?!http|https|\/)([^\"]*)\"/";
	$replace = "<a\${1} href=\"" . $base2 . "\${2}\"";
	$text = preg_replace($pattern, $replace, $text);

	$pattern = "/<a([^>]*) href=\"(?!http|https)([^\"]*)\"/";
	$replace = "<a\${1} href=\"" . $base . "\${2}\"";
	$text = preg_replace($pattern, $replace, $text);

	// Replace images
	$pattern = "/<img([^>]*) src=\"(?!http|https|\/)([^\"]*)\"/";
	$replace = "<img\${1} src=\"" . $base2 . "\${2}\"";
	$text = preg_replace($pattern, $replace, $text);

	$pattern = "/<img([^>]*) src=\"(?!http|https)([^\"]*)\"/";
	$replace = "<img\${1} src=\"" . $base . "\${2}\"";
	$text = preg_replace($pattern, $replace, $text);

	// Done
	return $text;
}

function item_post_type($item) {
	switch($item['resource_type']) {
		case 'photo':
			$post_type = t('photo');
			break;
		case 'event':
			$post_type = t('event');
			break;
		default:
			$post_type = t('post');
			if($item['mid'] != $item['parent_mid'])
				$post_type = t('comment');
			break;
	}

	if(strlen($item['verb']) && (! activity_match($item['verb'],ACTIVITY_POST)))
		$post_type = t('activity');

	if($item['obj_type'] === 'Question')
		$post_type = t('poll');

	return $post_type;
}

// This needs to be fixed to use quoted tag strings

function undo_post_tagging($s) {

	$matches = null;
	$x = null;
	// undo tags and mentions
	$cnt = preg_match_all('/([@#])(\!*)\[zrl=(.*?)\](.*?)\[\/zrl\]/ism',$s,$matches,PREG_SET_ORDER);
	if($cnt) {
		foreach($matches as $mtch) {
			$x = false;
			if($mtch[1] === '@') {
				$x = q("select xchan_addr, xchan_url from xchan where xchan_url = '%s' limit 1",
					dbesc($mtch[3])
				);
			}
			if($x) {
				$s = str_replace($mtch[0], $mtch[1] . $mtch[2] . '{' . (($x[0]['xchan_addr']) ? $x[0]['xchan_addr'] : $x[0]['xchan_url']) . '}', $s);
			}
			else {
				$s = str_replace($mtch[0], $mtch[1] . $mtch[2] . quote_tag($mtch[4]),$s);
			}
		}
	}
	// undo forum tags
	$cnt = preg_match_all('/\!\[zrl=(.*?)\](.*?)\[\/zrl\]/ism',$s,$matches,PREG_SET_ORDER);
	if($cnt) {
		foreach($matches as $mtch) {
			$x = q("select xchan_addr, xchan_url from xchan where xchan_url = '%s' limit 1",
				dbesc($mtch[1])
			);
			if($x) {
				$s = str_replace($mtch[0], '!' . '{' . (($x[0]['xchan_addr']) ? $x[0]['xchan_addr'] : $x[0]['xchan_url']) . '}', $s);
			}
			else {
				$s = str_replace($mtch[0], '!' . quote_tag($mtch[2]),$s);
			}
		}
	}

	return $s;
}

/**
 * @brief php to js string transfer
 * Hilmar, 20200227
 * String values built in php for using as content for js variables become sanitized. Often required
 * in cases, where some content will be translated by t(any text...) and is furthermore transfered via
 * templates to the outputted html stream. Redecoding in js not required nor useful.
 * Apply like: p2j(t('any text\nI will place on a "next line"'));
 */
function p2j($string) {
	return preg_replace('/\r?\n/', '\\n', addslashes($string));
}

function quote_tag($s) {
	if(strpos($s,' ') !== false)
		return '&quot;' . $s . '&quot;';
	return $s;
}


function fix_mce_lf($s) {
	$s = str_replace("\r\n","\n",$s);
//	$s = str_replace("\n\n","\n",$s);
	return $s;
}


function protect_sprintf($s) {
	return(str_replace('%','%%',$s));
}


function is_a_date_arg($s) {
	$i = intval($s);
	if($i > 1900) {
		$y = date('Y');
		if($i <= $y+1 && strpos($s,'-') == 4) {
			$m = intval(substr($s,5));
			if($m > 0 && $m <= 12)
				return true;
		}
	}

	return false;
}

function legal_webbie($s) {
	if(! $s)
		return '';

	// WARNING: This regex may not work in a federated environment.
	// You will probably want something like
	// preg_replace('/([^a-z0-9\_])/','',strtolower($s));

	$r = preg_replace('/([^a-z0-9\-\_])/','',strtolower($s));

	$x = [ 'input' => $s, 'output' => $r ];
	call_hooks('legal_webbie',$x);
	return $x['output'];

}

function legal_webbie_text() {

	// WARNING: This will not work in a federated environment.

	$s = t('a-z, 0-9, -, and _ only');

	$x = [ 'text' => $s ];
	call_hooks('legal_webbie_text',$x);
	return $x['text'];

}





function check_webbie($arr) {


	// These names conflict with the CalDAV server
	$taken = [ 'principals', 'addressbooks', 'calendars' ];

	$reservechan = get_config('system','reserved_channels');
	if(strlen($reservechan)) {
		$taken = array_merge($taken,explode(',', $reservechan));
	}

	$str = '';
	if(count($arr)) {
		foreach($arr as $x) {
			$y = legal_webbie($x);
			if(strlen($y)) {
				if($str)
					$str .= ',';
				$str .= "'" . dbesc($y) . "'";
			}
		}

		if(strlen($str)) {
			$r = q("select channel_address from channel where channel_address in ( $str ) ");
			if(count($r)) {
				foreach($r as $rr) {
					$taken[] = $rr['channel_address'];
				}
			}
			foreach($arr as $x) {
				$y = legal_webbie($x);
				if(! in_array($y,$taken)) {
					return $y;
				}
			}
		}
	}

	return '';
}

function ids_to_array($arr, $idx = 'id') {
	$t = [];
	if($arr) {
		foreach($arr as $x) {
			if(array_key_exists($idx, $x) && strlen($x[$idx]) && (! in_array($x[$idx], $t))) {
				$t[] = $x[$idx];
			}
		}
	}
	return $t;
}




function ids_to_querystr($arr, $idx = 'id', $quote = false) {
	$t = [];
	if($arr) {
		foreach($arr as $x) {
			if(!in_array($x[$idx], $t)) {
				if($quote)
					$t[] = "'" . dbesc($x[$idx]) . "'";
				else
					$t[] = $x[$idx];
			}
		}
	}
	return implode(',', $t);
}

/**
 * @brief Extract unique individual elements from an array of arrays and return
 * them as a string separated by a delimiter.
 *
 * Similar to ids_to_querystr, but allows a different delimiter instead of a
 * db-quote option empty elements (evaluated after trim()) are ignored.
 *
 * @see ids_to_querystr()
 *
 * @param array $arr
 * @param string $elm key to extract from sub-array
 * @param string $delim (optional) default ','
 * @param string $each (optional) default is 'trim'
 *   Filter function to apply to each element before evaluation.
 * @returns string
 */
function array_elm_to_str($arr, $elm, $delim = ',', $each = 'trim') {
	$tmp = [];

	if($arr && is_array($arr)) {
		foreach($arr as $x) {
			if(is_array($x) && array_key_exists($elm,$x)) {
				$z = $each($x[$elm]);
				if(($z) && (! in_array($z,$tmp))) {
					$tmp[] = $z;
				}
			}
		}
	}

	return implode($delim, $tmp);
}

function trim_and_unpunify($s) {
	return unpunify(trim($s));
}


/**
 * @brief Fetches xchan and hubloc data for an array of items with only an
 * author_xchan and owner_xchan.
 *
 * If $abook is true also include the abook info. This is needed in the API to
 * save extra per item lookups there.
 *
 * @param[in,out] array &$items
 * @param boolean $abook If true also include the abook info
 * @param number $effective_uid
 */
function xchan_query(&$items, $abook = true, $effective_uid = 0) {
	$arr = array();
	if($items && count($items)) {

		if($effective_uid) {
			for($x = 0; $x < count($items); $x ++) {
				$items[$x]['real_uid'] = $items[$x]['uid'];
				$items[$x]['uid'] = $effective_uid;
			}
		}

		foreach($items as $item) {
			if($item['owner_xchan'] && (! in_array("'" . dbesc($item['owner_xchan']) . "'",$arr)))
				$arr[] = "'" . dbesc($item['owner_xchan']) . "'";
			if($item['author_xchan'] && (! in_array("'" . dbesc($item['author_xchan']) . "'",$arr)))
				$arr[] = "'" . dbesc($item['author_xchan']) . "'";
		}
	}
	if(count($arr)) {
		if($abook) {
			$chans = q("select * from xchan left join hubloc on hubloc_hash = xchan_hash left join abook on abook_xchan = xchan_hash and abook_channel = %d
				where xchan_hash in (" . protect_sprintf(implode(',', $arr)) . ") and hubloc_deleted = 0 order by hubloc_primary desc",
				intval($item['uid'])
			);
		}
		else {
			$chans = q("select xchan.*,hubloc.* from xchan left join hubloc on hubloc_hash = xchan_hash
				where xchan_hash in (" . protect_sprintf(implode(',', $arr)) . ") and hubloc_deleted = 0 order by hubloc_primary desc");
		}
		$xchans = q("select * from xchan where xchan_hash in (" . protect_sprintf(implode(',',$arr)) . ") and xchan_network in ('rss','unknown', 'anon', 'token')");
		if(! $chans)
			$chans = $xchans;
		else
			$chans = array_merge($xchans,$chans);
	}
	if($items && count($items) && $chans && count($chans)) {
		for($x = 0; $x < count($items); $x ++) {
			$items[$x]['owner'] = find_xchan_in_array($items[$x]['owner_xchan'],$chans);
			$items[$x]['author'] = find_xchan_in_array($items[$x]['author_xchan'],$chans);
		}
	}
}

function find_xchan_in_array($xchan,$arr) {
	if(count($arr)) {
		foreach($arr as $x) {
			if($x['xchan_hash'] === $xchan) {
				return $x;
			}
		}
	}
	return array();
}

function get_rel_link($j,$rel) {
	if(is_array($j) && ($j))
		foreach($j as $l)
			if(array_key_exists('rel',$l) && $l['rel'] === $rel && array_key_exists('href',$l))
				return $l['href'];

	return '';
}


// Lots of code to write here

function magic_link($s) {
	return $s;
}

/**
 * @brief If $escape is true, dbesc() each element before adding quotes.
 *
 * @param[in,out] array &$arr
 * @param boolean $escape (optional) default false
 */
function stringify_array_elms(&$arr, $escape = false) {
	foreach($arr as $k => $v)
		$arr[$k] = "'" . (($escape) ? dbesc($v) : $v) . "'";
}


/**
 * @brief Similar to stringify_array_elms but returns a string. If $escape is true, dbesc() each element before adding quotes.
 *
 * @param array $arr
 * @param boolean $escape (optional) default false
 * @return string
 */
function stringify_array($arr, $escape = false) {
	if($arr) {
		stringify_array_elms($arr, $escape);
		return(implode(',', $arr));
	}
	return EMPTY_STR;
}


/**
 * @brief Indents a flat JSON string to make it more human-readable.
 *
 * @param string $json The original JSON string to process.
 * @return string Indented version of the original JSON string.
 */
function jindent($json) {

	$result    = '';
	$pos       = 0;
	$strLen    = strlen($json);
	$indentStr = '  ';
	$newLine   = "\n";
	$prevChar  = '';
	$outOfQuotes = true;

	for ($i=0; $i<=$strLen; $i++) {
		// Grab the next character in the string.
		$char = substr($json, $i, 1);

		// Are we inside a quoted string?
		if ($char == '"' && $prevChar != '\\') {
			$outOfQuotes = !$outOfQuotes;

		// If this character is the end of an element,
		// output a new line and indent the next line.
		} else if(($char == '}' || $char == ']') && $outOfQuotes) {
			$result .= $newLine;
			$pos --;
			for ($j=0; $j<$pos; $j++) {
				$result .= $indentStr;
			}
		}

		// Add the character to the result string.
		$result .= $char;

		// If the last character was the beginning of an element,
		// output a new line and indent the next line.
		if (($char == ',' || $char == '{' || $char == '[') && $outOfQuotes) {
			$result .= $newLine;
			if ($char == '{' || $char == '[') {
				$pos ++;
			}

			for ($j = 0; $j < $pos; $j++) {
				$result .= $indentStr;
			}
		}

		$prevChar = $char;
	}

	return $result;
}

/**
 * @brief Creates navigation menu for webpage, layout, blocks, menu sites.
 *
 * @return string with parsed HTML
 */
function design_tools() {

	$channel  = channelx_by_n(App::$profile['profile_uid']);
	$sys = false;

	if(App::$is_sys && is_site_admin()) {
		require_once('include/channel.php');
		$channel = get_sys_channel();
		$sys = true;
	}

	$who = $channel['channel_address'];

	return replace_macros(get_markup_template('design_tools.tpl'), array(
		'$title'  => t('Design Tools'),
		'$who'    => $who,
		'$sys'    => $sys,
		'$blocks' => t('Blocks'),
		'$menus'  => t('Menus'),
		'$layout' => t('Layouts'),
		'$pages'  => t('Pages')
	));
}

/**
 * @brief Creates website portation tools menu.
 *
 * @return string Parsed HTML code from template 'website_portation_tools.tpl'
 */
function website_portation_tools() {

	return replace_macros(get_markup_template('website_portation_tools.tpl'), [
		'$title'               => t('Import'),
		'$import_label'        => t('Import website...'),
		'$import_placeholder'  => t('Select folder to import'),
		'$file_upload_text'    => t('Import from a zipped folder:'),
		'$file_import_text'    => t('Import from cloud files:'),
		'$desc'                => t('/cloud/channel/path/to/folder'),
		'$hint'                => t('Enter path to website files'),
		'$select'              => t('Select folder'),
		'$export_label'        => t('Export website...'),
		'$file_download_text'  => t('Export to a zip file'),
		'$filename_desc'       => t('website.zip'),
		'$filename_hint'       => t('Enter a name for the zip file.'),
		'$cloud_export_text'   => t('Export to cloud files'),
		'$cloud_export_desc'   => t('/path/to/export/folder'),
		'$cloud_export_hint'   => t('Enter a path to a cloud files destination.'),
		'$cloud_export_select' => t('Specify folder'),
	]);
}

/**
 * @brief case insensitive in_array()
 *
 * @param string $needle
 * @param array $haystack
 * @return boolean
 */
function in_arrayi($needle, $haystack) {
	return in_array(strtolower($needle), array_map('strtolower', $haystack));
}

function normalise_openid($s) {
	return trim(str_replace(array('http://','https://'),array('',''),$s),'/');
}

/**
 * Used in ajax endless scroll request to find out all the args that the master page was viewing.
 * This was using $_REQUEST, but $_REQUEST also contains all your cookies. So we're restricting it
 * to $_GET and $_POST.
 *
 * @return string with additional URL parameters
 */
function extra_query_args() {
	$s = '';
	if(count($_GET)) {
		foreach($_GET as $k => $v) {
			// these are request vars we don't want to duplicate
			if(! in_array($k, array('q','f','zid','page','PHPSESSID'))) {
				$s .= '&' . $k . '=' . urlencode($v);
			}
		}
	}
	if(count($_POST)) {
		foreach($_POST as $k => $v) {
			// these are request vars we don't want to duplicate
			if(! in_array($k, array('q','f','zid','page','PHPSESSID'))) {
				$s .= '&' . $k . '=' . urlencode($v);
			}
		}
	}

	return $s;
}

/**
 * @brief This function removes the tag $tag from the text $body and replaces it
 * with the appropiate link.
 *
 * @param App $a
 * @param[in,out] string &$body the text to replace the tag in
 * @param[in,out] string &$access_tag used to return tag ACL exclusions e.g. @!foo
 * @param[in,out] string &$str_tags string to add the tag to
 * @param int $profile_uid
 * @param string $tag the tag to replace
 * @param boolean $in_network default true
 * @return boolean true if replaced, false if not replaced
 */
function handle_tag(&$body, &$str_tags, $profile_uid, $tag, $in_network = true) {

	$channel = App::get_channel();
	$replaced = false;
	$r = null;
	$match = array();

	$termtype = ((strpos($tag,'#') === 0)   ? TERM_HASHTAG  : TERM_UNKNOWN);
	$termtype = ((strpos($tag,'@') === 0)   ? TERM_MENTION  : $termtype);
//	$termtype = ((strpos($tag,'!') === 0)   ? TERM_FORUM    : $termtype);
	$termtype = ((strpos($tag,'#^[') === 0) ? TERM_BOOKMARK : $termtype);

	// Is it a hashtag of some kind?

	if ( in_array($termtype, [ TERM_HASHTAG, TERM_BOOKMARK ] )) {
		if($termtype === TERM_BOOKMARK) {
			if(preg_match('/#\^\[(url|zrl)(.*?)\](.*?)\[\/(url|zrl)\]/',$tag,$match)) {
				$basetag = $match[3];
				$url = ((substr($match[2],0,1) === '=') ? substr($match[2],1) : $match[3]);
				$replaced = true;
			}
		}
		// if the tag is already replaced...
		elseif((strpos($tag,'[zrl=')) || (strpos($tag,'[url='))) {
			// ...do nothing
			return $replaced;
		}

		if(! $replaced) {

			// double-quoted hashtags: base tag has the htmlentity name only

			if((substr($tag,0,7) === '#&quot;') && (substr($tag,-6,6) === '&quot;')) {
				$basetag = substr($tag,7);
				$basetag = substr($basetag,0,-6);
			}
			elseif((substr($tag,0,2) === '#"') && (substr($tag,-1,1) === '"')) {
				$basetag = substr($tag,2);
				$basetag = substr($basetag,0,-1);
			}
			else
				$basetag = substr($tag,1);

			// create text for link

			$url = z_root() . '/search?tag=' . rawurlencode($basetag);
			$newtag = '#[zrl=' . z_root() . '/search?tag=' . rawurlencode($basetag) . ']' . $basetag . '[/zrl]';

			// replace tag by the link. Make sure to not replace something in the middle of a word

			$body = preg_replace('/(?<![a-zA-Z0-9=\/])'.preg_quote($tag,'/').'/', $newtag, $body);
			$replaced = true;
		}

		// is the link already in str_tags?
		if(! stristr($str_tags,$newtag)) {
			// append or set str_tags
			if(strlen($str_tags))
				$str_tags .= ',';

			$str_tags .= $newtag;
		}
		return [ [
			'replaced'   => $replaced,
			'termtype'   => $termtype,
			'term'       => $basetag,
			'url'        => $url,
			'contact'    => [],
			'access_tag' => '',
		]];
	}

	// END hashtags

	// BEGIN mentions

	if ($termtype === TERM_MENTION) {

		// The @! tag will alter permissions

		// $in_network is set to false to avoid false positives on posts originating
		// on a network which does not implement privacy tags or implements them differently.

		$exclusive = (((strpos(substr($tag,1), '!') === 0) && $in_network) ? true : false);

		//is it already replaced?
		if(strpos($tag,"[zrl=") || strpos($tag,"[url="))
			return $replaced;

		// get the channel name
		// First extract the name or name fragment we are going to replace

		$name = substr($tag,(($exclusive) ? 2 : 1));
		$newname = $name; // make a copy that we can mess with

		$r = null;

		// is it some generated (autocompleted) name?

		if(substr($name,0,1) === '{' && substr($name,-1,1) === '}') {
			$newname = substr($name,1);
			$newname = substr($newname,0,-1);

			$r = q("SELECT * FROM xchan LEFT JOIN hubloc ON hubloc_hash = xchan_hash WHERE ( xchan_addr = '%s' OR xchan_url = '%s' ) AND xchan_deleted = 0 AND NOT xchan_network  IN ('rss', 'anon', 'unknown') ORDER BY hubloc_id DESC",
				dbesc($newname),
				dbesc($newname)
			);
		}

		if(! $r) {
			// look for matching names in the address book

			// Double quote the entire mentioned term to include special characters
			// such as spaces and some punctuation.

			// We see this after input filtering so quotes have been html entity encoded

			if((substr($name,0,6) === '&quot;') && (substr($name,-6,6) === '&quot;')) {
				$newname = substr($name,6);
				$newname = substr($newname,0,-6);
			}
			elseif((substr($name,0,1) === '"') && (substr($name,-1,1) === '"')) {
				$newname = substr($name,1);
				$newname = substr($newname,0,-1);
			}

			// select someone from this user's contacts by name

			$r = q("SELECT * FROM abook LEFT JOIN xchan ON abook_xchan = xchan_hash
				WHERE xchan_name = '%s' AND abook_channel = %d AND xchan_deleted = 0 AND NOT xchan_network  IN ('rss', 'anon', 'unknown')",
				dbesc($newname),
				intval($profile_uid)
			);

			// select anybody by full hubloc_addr

			if((! $r) && strpos($newname,'@')) {
				$r = q("SELECT * FROM xchan LEFT JOIN hubloc ON xchan_hash = hubloc_hash
					WHERE hubloc_addr = '%s' AND xchan_deleted = 0 AND NOT xchan_network  IN ('rss', 'anon', 'unknown') ORDER BY hubloc_id DESC",
					dbesc($newname)
				);
			}

			// select someone by attag or nick and the name passed in

			if(! $r) {
				// strip user-supplied wildcards before running a wildcard search
				$newname = str_replace('%','',$newname);

				$r = q("SELECT * FROM abook LEFT JOIN xchan ON abook_xchan = xchan_hash
					WHERE xchan_addr LIKE ('%s') AND abook_channel = %d AND xchan_deleted = 0 AND NOT xchan_network  IN ('rss', 'anon', 'unknown')",
					dbesc(((strpos($newname,'@')) ? $newname : $newname . '@%')),
					intval($profile_uid)
				);
			}

		}

		$fn_results = [];
		$access_tag = EMPTY_STR;
		$url = EMPTY_STR;

		// $r is set if we found something

		if($r) {

			$xc = Libzot::zot_record_preferred($r, 'xchan_network');

			$profile = $xc['xchan_url'];
			$newname = $xc['xchan_name'];
			// add the channel's xchan_hash to $access_tag if exclusive
			if($exclusive) {
				$access_tag = 'cid:' . $xc['xchan_hash'];
			}

			// if there is a url for this channel

			if(isset($profile)) {
				$replaced = true;
				//create profile link
				$profile = str_replace(',','%2c',$profile);
				$url = $profile;
				$bb_tag = (($xc['xchan_network'] === 'zot6') ? 'zrl' : 'url');

				$newtag = '@' . (($exclusive) ? '!' : '') . '[' . $bb_tag . '=' . $profile . ']' . $newname	. '[/' . $bb_tag . ']';

				// Replace tag but make sure to not replace something in the middle of a word
				$body = preg_replace('/(?<![a-zA-Z0-9=\/])' . preg_quote($tag, '/') . '/', $newtag, $body);
				// $body = str_replace('@' . (($exclusive) ? '!' : '') . $name, $newtag, $body);

				// append tag to str_tags
				if(! stristr($str_tags,$newtag)) {
					if(strlen($str_tags))
						$str_tags .= ',';
					$str_tags .= $newtag;
				}
			}


			$fn_results[] =  [
				'replaced'   => $replaced,
				'termtype'   => $termtype,
				'term'       => $newname,
				'url'        => $url,
				'access_tag' => $access_tag,
				'contact'    => (($r) ? $xc : []),
			];

		}
		else {

			// check for a group/collection exclusion tag

			// note that we aren't setting $replaced even though we're replacing text.
			// This tag isn't going to get a term attached to it. It's only used for
			// access control. The link points to out own channel just so it doesn't look
			// weird - as all the other tags are linked to something.

			if(local_channel() && local_channel() == $profile_uid) {
				$grp = AccessList::by_name($profile_uid,$name);

				if($grp) {
					$g = q("select hash from pgrp where id = %d and visible = 1 limit 1",
						intval($grp)
					);
					if($g && $exclusive) {
						$access_tag .= 'gid:' . $g[0]['hash'];
					}
					$channel = App::get_channel();
					if($channel) {
						$newtag = '@' . (($exclusive) ? '!' : '') . '[zrl=' . z_root() . '/channel/' . $channel['channel_address'] . ']' . $newname . '[/zrl]';
						// Replace tag but make sure to not replace something in the middle of a word
						$body = preg_replace('/(?<![a-zA-Z0-9=\/])' . preg_quote($tag, '/') . '/', $newtag, $body);
						// $body = str_replace('@' . (($exclusive) ? '!' : '') . $name, $newtag, $body);
					}
				}
			}


			// if there is a url for this channel

			if(isset($profile)) {
				$replaced = true;
				//create profile link
				$profile = str_replace(',','%2c',$profile);
				$url = $profile;
/*
				if($termtype === TERM_FORUM) {
					$newtag = '!' . (($exclusive) ? '!' : '') . '[zrl=' . $profile . ']' . $newname	. '[/zrl]';
					$body = str_replace('!' . (($exclusive) ? '!' : '') . $name, $newtag, $body);
				}
*/
				if ($termtype === TERM_MENTION) {
					$newtag = '@' . (($exclusive) ? '!' : '') . '[zrl=' . $profile . ']' . $newname	. '[/zrl]';
					// Replace tag but make sure to not replace something in the middle of a word
					$body = preg_replace('/(?<![a-zA-Z0-9=\/])' . preg_quote($tag, '/') . '/', $newtag, $body);
					// $body = str_replace('@' . (($exclusive) ? '!' : '') . $name, $newtag, $body);
				}

				// append tag to str_tags
				if(! stristr($str_tags,$newtag)) {
					if(strlen($str_tags))
						$str_tags .= ',';
					$str_tags .= $newtag;
				}
			}

			$fn_results[] = [
				'replaced'   => $replaced,
				'termtype'   => $termtype,
				'term'       => $newname,
				'url'        => $url,
				'access_tag' => $access_tag,
				'contact'    => [],
			];
		}
	}

	return $fn_results;

}

function linkify_tags(&$body, $uid, $in_network = true) {
	$str_tags = EMPTY_STR;
	$results = [];

	$tags = get_tags($body);

	if(count($tags)) {
		foreach($tags as $tag) {

			$success = handle_tag($body, $str_tags, ($uid) ? $uid : App::$profile_uid , $tag, $in_network);

			foreach($success as $handled_tag) {
				$results[] = [ 'success' => $handled_tag ];
			}
		}
	}

	return $results;
}

/**
 * @brief returns icon name for use with e.g. font-awesome based on mime-type.
 *
 * These are the the font-awesome names of version 3.2.1. The newer font-awesome
 * 4 has different names.
 *
 * @param string $type mime type
 * @return string
 * @todo rename to get_icon_from_type()
 */
function getIconFromType($type) {
	$iconMap = array(
		//Folder
		'Collection' => 'fa-folder-o',
		'multipart/mixed' => 'fa-folder-o', //dirs in attach use this mime type
		//Common file
		'application/octet-stream' => 'fa-file-o',
		//Text
		'text/plain' => 'fa-file-text-o',
		'text/markdown' => 'fa-file-text-o',
		'text/bbcode' => 'fa-file-text-o',
		'text/html' => 'fa-file-text-o',
		'application/msword' => 'fa-file-word-o',
		'application/pdf' => 'fa-file-pdf-o',
		'application/vnd.oasis.opendocument.text' => 'fa-file-word-o',
		'application/epub+zip' => 'fa-book',
		//Spreadsheet
		'application/vnd.oasis.opendocument.spreadsheet' => 'fa-file-excel-o',
		'application/vnd.ms-excel' => 'fa-file-excel-o',
		//Image
		'image/jpeg' => 'fa-picture-o',
		'image/png' => 'fa-picture-o',
		'image/gif' => 'fa-picture-o',
		'image/webp' => 'fa-picture-o',
		'image/svg+xml' => 'fa-picture-o',
		//Archive
		'application/zip' => 'fa-file-archive-o',
		'application/x-rar-compressed' => 'fa-file-archive-o',
		//Audio
		'audio/mpeg' => 'fa-file-audio-o',
		'audio/wav' => 'fa-file-audio-o',
		'application/ogg' => 'fa-file-audio-o',
		'audio/ogg' => 'fa-file-audio-o',
		'audio/webm' => 'fa-file-audio-o',
		'audio/mp4' => 'fa-file-audio-o',
		//Video
		'video/quicktime' => 'fa-file-video-o',
		'video/webm' => 'fa-file-video-o',
		'video/mp4' => 'fa-file-video-o',
		'video/x-matroska' => 'fa-file-video-o'
	);

	$catMap = [
		'application' => 'fa-file-code-o',
		'multipart'   => 'fa-folder',
		'audio'       => 'fa-file-audio-o',
		'video'       => 'fa-file-video-o',
		'text'        => 'fa-file-text-o',
		'image'       => 'fa=file-picture-o',
		'message'     => 'fa-file-text-o'
	];


	$iconFromType = '';

	if (array_key_exists($type, $iconMap)) {
		$iconFromType = $iconMap[$type];
	}
	else {
		$parts = explode('/',$type);
		if($parts[0] && $catMap[$parts[0]]) {
			$iconFromType = $catMap[$parts[0]];
		}
	}

	if(! $iconFromType)	{
		$iconFromType = 'fa-file-o';
	}


	return $iconFromType;
}

/**
 * @brief Returns a human readable formatted string for filesizes.
 *
 * @param int $size filesize in bytes
 * @return string human readable formatted filesize
 * @todo rename to user_readable_size()
 */
function userReadableSize($size) {
	$ret = '';
	if (is_numeric($size)) {
		$incr = 0;
		$k = 1024;
		$unit = array('bytes', 'KB', 'MB', 'GB', 'TB', 'PB');
		while (($size / $k) >= 1){
			$incr++;
			$size = round($size / $k, 2);
		}
		$ret = $size . ' ' . $unit[$incr];
	}

	return $ret;
}

function str_rot47($str) {
	return strtr($str,
		'!"#$%&\'()*+,-./0123456789:;<=>?@ABCDEFGHIJKLMNOPQRSTUVWXYZ[\]^_`abcdefghijklmnopqrstuvwxyz{|}~',
		'PQRSTUVWXYZ[\]^_`abcdefghijklmnopqrstuvwxyz{|}~!"#$%&\'()*+,-./0123456789:;<=>?@ABCDEFGHIJKLMNO');
}


function string_replace($old,$new,&$s) {

	$x = str_replace($old,$new,$s);
	$replaced = false;
	if($x !== $s) {
		$replaced = true;
	}
	$s = $x;
	return $replaced;
}


function json_url_replace($old,$new,&$s) {

	$old = str_replace('/','\\/',$old);
	$new = str_replace('/','\\/',$new);

	$x = str_replace($old,$new,$s);
	$replaced = false;
	if($x !== $s) {
		$replaced = true;
	}
	$s = $x;
	return $replaced;
}

function item_url_replace($channel, &$item, $old, $new, $oldnick = '') {

	if (!empty($item['attach'])) {
		$converted = false;
		if (is_array($item['attach'])) {
			$item['attach'] = item_json_encapsulate($item,'attach');
			$converted = true;
		}
		json_url_replace($old, $new, $item['attach']);
		if ($oldnick && ($oldnick !== $channel['channel_address'])) {
			json_url_replace('/' . $oldnick . '/', '/' . $channel['channel_address'] . '/', $item['attach']);
		}
		if ($converted) {
			$item['attach'] = json_decode($item['attach'],true);
		}
	}

	if (!empty($item['obj'])) {
		$converted = false;
		if (is_array($item['obj'])) {
			$item['obj'] = item_json_encapsulate($item,'obj');
			$converted = true;
		}
		json_url_replace($old, $new, $item['obj']);
		if ($oldnick && ($oldnick !== $channel['channel_address'])) {
			json_url_replace('/' . $oldnick . '/', '/' . $channel['channel_address'] . '/', $item['obj']);
		}
		if ($converted) {
			$item['obj'] = json_decode($item['obj'],true);
		}
	}

	if (!empty($item['target'])) {
		$converted = false;
		if (is_array($item['target'])) {
			$item['target'] = item_json_encapsulate($item,'target');
			$converted = true;
		}
		json_url_replace($old, $new, $item['target']);
		if ($oldnick && ($oldnick !== $channel['channel_address'])) {
			json_url_replace('/' . $oldnick . '/', '/' . $channel['channel_address'] . '/', $item['target']);
		}
		if ($converted) {
			$item['target'] = json_decode($item['target'],true);
		}
	}

	// FIXME: ignore anything in a share tag
	$item['body'] = str_replace($old, $new, $item['body']);

	if ($oldnick && ($oldnick !== $channel['channel_address'])) {
		$item['body'] = str_replace('/' . $oldnick . '/', '/' . $channel['channel_address'] . '/', $item['body']);
	}

	$item['sig'] = Libzot::sign($item['body'], $channel['channel_prvkey']);
	$item['item_verified'] = 1;

	if (isset($item['plink'])) {
		$item['plink'] = str_replace($old, $new, $item['plink']);
		if ($oldnick && ($oldnick !== $channel['channel_address'])) {
			$item['plink'] = str_replace('/' . $oldnick . '/', '/' . $channel['channel_address'] . '/', $item['plink']);
		}
	}

	if (isset($item['llink'])) {
		$item['llink'] = str_replace($old, $new, $item['llink']);
		if ($oldnick && ($oldnick !== $channel['channel_address'])) {
			$item['llink'] = str_replace('/' . $oldnick . '/', '/' . $channel['channel_address'] . '/', $item['llink']);
		}
	}

	if (isset($item['term']) && is_array($item['term'])) {
		for ($x = 0; $x < count($item['term']); $x++) {
			$item['term'][$x]['url'] =  str_replace($old, $new, $item['term'][$x]['url']);
			if ($oldnick && ($oldnick !== $channel['channel_address'])) {
				$item['term'][$x]['url'] = str_replace('/' . $oldnick . '/', '/' . $channel['channel_address'] . '/', $item['term'][$x]['url']);
			}
		}
	}
}

/**
 * @brief Used to wrap ACL elements in angle brackets for storage.
 *
 * @param[in,out] array &$item
 */
function sanitise_acl(&$item) {
	if (strlen($item))
		$item = '<' . notags(trim(urldecode($item))) . '>';
	else
		unset($item);
}

/**
 * @brief Convert an ACL array to a storable string.
 *
 * @param array $p
 * @return array
 */
function perms2str($p) {
	$ret = '';

	if (is_array($p))
		$tmp = $p;
	else
		$tmp = explode(',', $p);

	if (is_array($tmp)) {
		array_walk($tmp, 'sanitise_acl');
		$ret = implode('', $tmp);
	}

	return $ret;
}

/**
 * @brief Turn user/group ACLs stored as angle bracketed text into arrays.
 *
 * turn string array of angle-bracketed elements into string array
 * e.g. "<123xyz><246qyo><sxo33e>" => array(123xyz,246qyo,sxo33e);
 *
 * @param string $s
 * @return array
 */
function expand_acl($s) {
	$ret = array();

	if(strlen($s)) {
		$t = str_replace('<','',$s);
		$a = explode('>',$t);
		foreach($a as $aa) {
			if($aa)
				$ret[] = $aa;
		}
	}

	return $ret;
}

function acl2json($s) {
	$s = expand_acl($s);
	$s = json_encode($s);

	return $s;
}

/**
 * @brief When editing a webpage - a dropdown is needed to select a page layout
 *
 * On submit, the pdl_select value (which is the mid of an item with item_type = ITEM_TYPE_PDL)
 * is stored in the webpage's resource_id, with resource_type 'pdl'.
 *
 * Then when displaying a webpage, we can see if it has a pdl attached. If not we'll
 * use the default site/page layout.
 *
 * If it has a pdl we'll load it as we know the mid and pass the body through comanche_parser() which will generate the
 * page layout from the given description
 *
 * @FIXME - there is apparently a very similar function called layout_select; this one should probably take precedence
 * and the other should be checked for compatibility and removed
 *
 * @param int $uid
 * @param string $current
 * @return string HTML code for dropdown
 */
function pdl_selector($uid, $current='') {
	$o = '';

	$sql_extra = item_permissions_sql($uid);

	$r = q("select iconfig.*, mid from iconfig left join item on iconfig.iid = item.id
		where item.uid = %d and iconfig.cat = 'system' and iconfig.k = 'PDL' $sql_extra order by v asc",
		intval($uid)
	);

	$arr = ['channel_id' => $uid, 'current' => $current, 'entries' => $r];
	/**
	 * @hooks pdl_selector
	 *   * \e int \b channel_id
	 *   * \e string \b current
	 *   * \e array \b entries - Result from database query
	 */
	call_hooks('pdl_selector', $arr);

	$entries = $arr['entries'];
	$current = $arr['current'];

	$o .= '<select name="pdl_select" id="pdl_select" size="1">';
	$entries[] = array('title' => t('Default'), 'mid' => '');
	foreach($entries as $selection) {
		$selected = (($selection == $current) ? ' selected="selected" ' : '');
		$o .= "<option value=\"{$selection['mid']}\" $selected >{$selection['v']}</option>";
	}

	$o .= '</select>';
	return $o;
}

/**
 * @brief returns a one-dimensional array from a multi-dimensional array
 * empty values are discarded
 *
 * example: print_r(flatten_array_recursive(array('foo','bar',array('baz','blip',array('zob','glob')),'','grip')));
 *
 * Array ( [0] => foo [1] => bar [2] => baz [3] => blip [4] => zob [5] => glob [6] => grip )
 *
 * @param array $arr multi-dimensional array
 * @return one-dimensional array
 */
function flatten_array_recursive($arr) {
	$ret = array();

	if(! $arr)
		return $ret;

	foreach($arr as $a) {
		if(is_array($a)) {
			$tmp = flatten_array_recursive($a);
			if($tmp) {
				$ret = array_merge($ret, $tmp);
			}
		}
		elseif(isset($a)) {
			$ret[] = $a;
		}
	}

	return($ret);
}

// Turn $element into an array if it isn't already.
function force_array($element) {
	if (empty($element)) {
		return [];
	}
	return (is_array($element)) ? $element : [$element];
}

/**
 * @brief Highlight Text.
 *
 * @param string $s Text to highlight
 * @param string $lang Which language should be highlighted
 * @return string
 *     Important: The returned text has the text pattern 'http' translated to '%eY9-!' which should be converted back
 * after further processing. This was done to prevent oembed links from occurring inside code blocks.
 * See include/bbcode.php
 */
function text_highlight($s, $lang) {

	if($lang === 'js')
		$lang = 'javascript';

	if($lang === 'json') {
		$lang = 'javascript';
		if(! strpos(trim($s), "\n"))
			$s = jindent($s);
	}

	$arr = [
			'text' => $s,
			'language' => $lang,
			'success' => false
	];
	/**
	 * @hooks text_highlight
	 *   * \e string \b text
	 *   * \e string \b language
	 *   * \e boolean \b success default false
	 */
	call_hooks('text_highlight', $arr);

	if($arr['success'])
		$o = $arr['text'];
	else
		$o = $s;

	$o = str_replace('http','%eY9-!',$o);

	return('<code>' . $o . '</code>');
}

// function to convert multi-dimensional array to xml
// create new instance of simplexml

// $xml = new SimpleXMLElement('<root/>');

// function callback
// array2XML($xml, $my_array);

// save as xml file
// echo (($xml->asXML('data.xml')) ? 'Your XML file has been generated successfully!' : 'Error generating XML file!');

function arrtoxml($root_elem,$arr) {
	$xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><' . $root_elem . '></' . $root_elem . '>', 0, false);
	array2XML($xml,$arr);

	return $xml->asXML();
}

function array2XML($obj, $array) {
	foreach ($array as $key => $value) {
		if(is_numeric($key))
			$key = 'item' . $key;

		if(is_array($value)) {
			$node = $obj->addChild($key);
			array2XML($node, $value);
		}
		else {
			$obj->addChild($key, htmlspecialchars($value));
		}
	}
}

/**
 * @brief Inserts an array into $table.
 *
 * @TODO Why is this function in include/text.php?
 *
 * @param string $table
 * @param array $arr
 * @param array $binary_fields - fields which will be cleansed with dbescbin rather than dbesc; this is critical for postgres
 * @return boolean|PDOStatement
 */
function create_table_from_array($table, $arr, $binary_fields = []) {

	if(! ($arr && $table))
		return false;

	$columns = db_columns($table);

	$clean = [];
	foreach($arr as $k => $v) {

		if(! in_array($k,$columns)) {
			continue;
		}


		$matches = false;
		if(preg_match('/([^a-zA-Z0-9\-\_\.])/',$k,$matches)) {
			return false;
		}
		if(in_array($k,$binary_fields)) {
			$clean[$k] = dbescbin($v);
		}
		else {
			$clean[$k] = dbesc($v);
		}
	}
	$r = dbq("INSERT INTO " . TQUOT . $table . TQUOT . " (" . TQUOT
		. implode(TQUOT . ', ' . TQUOT, array_keys($clean))
		. TQUOT . ") VALUES ('"
		. implode("', '", array_values($clean))
		. "')"
	);

	return $r;
}


function update_table_from_array($table, $arr, $where, $binary_fields = []) {

	if (! ($arr && $table)) {
		return false;
	}

	$columns = db_columns($table);

	$clean = [];
	foreach ($arr as $k => $v) {
		if (! in_array($k, $columns)) {
			continue;
		}

		$matches = false;
		if (preg_match('/([^a-zA-Z0-9\-\_\.])/', $k, $matches)) {
			return false;
		}
		if (in_array($k, $binary_fields)) {
			$clean[$k] = dbescbin($v);
		} else {
			$clean[$k] = dbesc($v);
		}
	}

	$sql = "UPDATE " . TQUOT . $table . TQUOT . " SET ";

	foreach ($clean as $k => $v) {
		$sql .= TQUOT . $k . TQUOT . ' = "' . $v . '",';
	}

	$sql = rtrim($sql,',');

	$r = dbq($sql . " WHERE " . $where);

    return $r;
}

function share_shield($m) {
	return str_replace($m[1],'!=+=+=!' . base64url_encode($m[1]) . '=+!=+!=',$m[0]);
}

function share_unshield($m) {
	$x = str_replace(array('!=+=+=!','=+!=+!='),array('',''),$m[1]);
	return str_replace($m[1], base64url_decode($x), $m[0]);
}


function cleanup_bbcode($body) {

	/**
	 * fix naked links by passing through a callback to see if this is a hubzilla site
	 * (already known to us) which will get a zrl, otherwise link with url, add bookmark tag to both.
	 * First protect any url inside certain bbcode tags so we don't double link it.
	 */

	$body = preg_replace_callback('/\[code(.*?)\[\/(code)\]/ism','\red_escape_codeblock',$body);
	$body = preg_replace_callback('/\[summary(.*?)\[\/(summary)\]/ism','\red_escape_codeblock',$body);
	$body = preg_replace_callback('/\[url(.*?)\[\/(url)\]/ism','\red_escape_codeblock',$body);
	$body = preg_replace_callback('/\[zrl(.*?)\[\/(zrl)\]/ism','\red_escape_codeblock',$body);
	$body = preg_replace_callback('/\[svg(.*?)\[\/(svg)\]/ism','\red_escape_codeblock',$body);
	$body = preg_replace_callback('/\[img(.*?)\[\/(img)\]/ism','\red_escape_codeblock',$body);
	$body = preg_replace_callback('/\[zmg(.*?)\[\/(zmg)\]/ism','\red_escape_codeblock',$body);

	$body = preg_replace_callback("/([^\]\='".'"'."\;\/\{]|^|\#\^)(https?\:\/\/[a-zA-Z0-9\pL\:\/\-\?\&\;\.\=\@\_\~\#\%\$\!\\
+\,\(\)]+)/ismu", '\nakedoembed', $body);

	$body = preg_replace_callback("/([^\]\='".'"'."\;\/\{]|^|\#\^)(https?\:\/\/[a-zA-Z0-9\pL\:\/\-\?\&\;\.\=\@\_\~\#\%\$\!\\
+\,\(\)]+)/ismu", '\red_zrl_callback', $body);


	$body = preg_replace_callback('/\[\$b64code(.*?)\[\/(code)\]/ism','\red_unescape_codeblock',$body);
	$body = preg_replace_callback('/\[\$b64summary(.*?)\[\/(summary)\]/ism','\red_unescape_codeblock',$body);
	$body = preg_replace_callback('/\[\$b64url(.*?)\[\/(url)\]/ism','\red_unescape_codeblock',$body);
	$body = preg_replace_callback('/\[\$b64zrl(.*?)\[\/(zrl)\]/ism','\red_unescape_codeblock',$body);
	$body = preg_replace_callback('/\[\$b64svg(.*?)\[\/(svg)\]/ism','\red_unescape_codeblock',$body);
	$body = preg_replace_callback('/\[\$b64img(.*?)\[\/(img)\]/ism','\red_unescape_codeblock',$body);
	$body = preg_replace_callback('/\[\$b64zmg(.*?)\[\/(zmg)\]/ism','\red_unescape_codeblock',$body);

	// fix any img tags that should be zmg

	$body = preg_replace_callback('/\[img(.*?)\](.*?)\[\/img\]/ism','\red_zrlify_img_callback',$body);


	$body = bb_translate_video($body);

	/**
	 * Fold multi-line [code] sequences
	 */

	$body = preg_replace('/\[\/code\]\s*\[code\]/ism',"\n",$body);

	return $body;
}

function gen_link_id($mid) {
	if(strpbrk($mid,':/&?<>"\'') !== false)
		return 'b64.' . base64url_encode($mid);
	return $mid;
}

/**
 * @brief check if the provided string starts with 'b64.' and try to decode it if so.
 * If it could be decoded return the decoded string or false if decoding failed.
 * If the string does not start with 'b64.', return the string as is.
 *
 * @param string $mid
 * @return string|boolean false
 */
function unpack_link_id($mid) {
	if (is_string($mid) && strpos($mid, 'b64.') === 0) {
		$mid = @base64url_decode(substr($mid, 4), true);
		return $mid;
	}
	return $mid;
}

// callback for array_walk

function array_trim(&$v,$k) {
	$v = trim($v);
}

function array_escape_tags(&$v,$k) {
	$v = escape_tags($v);
}

function ellipsify($s,$maxlen) {
	if($maxlen & 1)
		$maxlen --;
	if($maxlen < 4)
		$maxlen = 4;

	if(mb_strlen($s) < $maxlen)
		return $s;

	return mb_substr($s,0,$maxlen / 2) . '...' . mb_substr($s,mb_strlen($s) - ($maxlen / 2));
}

function purify_filename($s) {
	if(($s[0] === '.') || strpos($s,'/') !== false)
		return '';
	return $s;
}

// callback for sorting the settings/featured entries.

function featured_sort($a,$b) {
	$s1 = substr($a,strpos($a,'id='),20);
	$s2 = substr($b,strpos($b,'id='),20);
	return(strcmp($s1,$s2));
}


function unpunify($s) {
	if (function_exists('idn_to_utf8') && isset($s)) {
		return idn_to_utf8($s);
	}
	return $s;
}


function punify($s) {
	if (function_exists('idn_to_ascii') && isset($s)) {
		return idn_to_ascii($s);
	}
	return $s;
}

function unique_multidim_array($array, $key) {
    $temp_array = array();
    $i = 0;
    $key_array = array();

    foreach($array as $val) {
        if (!in_array($val[$key], $key_array)) {
            $key_array[$i] = $val[$key];
            $temp_array[$i] = $val;
        }
        $i++;
    }
    return $temp_array;
}

function get_forum_channels($uid) {

	if(! $uid)
		return;

	$r = q("select abook_id, xchan_pubforum, xchan_hash, xchan_network, xchan_name, xchan_url, xchan_photo_s from abook left join xchan on abook_xchan = xchan_hash where xchan_deleted = 0 and abook_channel = %d and abook_pending = 0 and abook_ignored = 0 and abook_blocked = 0 and abook_archived = 0 and abook_self = 0 and xchan_pubforum = 1 order by xchan_name",
		intval($uid)
	);


/*

	if(isset(App::$data['forum_channels']))
		return App::$data['forum_channels'];

	$xf = '';

	$x1 = q("select xchan from abconfig where chan = %d and cat = 'their_perms' and k = 'send_stream' and v = '0'",
		intval($uid)
	);

	if($x1) {

		$x2 = [];
		$x3 = [];
		$x4 = [];

		$xc = ids_to_querystr($x1,'xchan',true);

		$x2 = q("select xchan from abconfig where chan = %d and cat = 'their_perms' and k = 'tag_deliver' and v = '1' and xchan in (" . protect_sprintf($xc) . ") ",
			intval($uid)
		);

		$xf = ids_to_querystr($x2,'xchan',true);
		$sql_extra = (($xf) ? ' and not xchan in (' . $xf . ')' : '');

		// private forums
		$x3 = q("select xchan from abconfig where chan = %d and cat = 'their_perms' and k = 'post_wall' and v = '1' and xchan in (" . protect_sprintf($xc) . ") $sql_extra ",
			intval(local_channel())
		);
		if($x3) {
			$xf = ids_to_querystr(array_merge($x2,$x3),'xchan',true);
		}

		// public forums with no permission to post
		$x4 = q("select xchan from abconfig left join xchan on xchan = xchan_hash where chan = %d and cat = 'their_perms' and k in ('post_wall', 'tag_deliver') and v = '0' and xchan in (" . protect_sprintf($xc) . ") and xchan_pubforum = 1 $sql_extra ",
			intval(local_channel())
		);
		if($x4) {
			$xf = ids_to_querystr(array_merge($x2,$x3,$x4),'xchan',true);
		}

	}

	$sql_extra_1 = (($xf) ? " and ( xchan_hash in (" . protect_sprintf($xf) . ") or xchan_pubforum = 1 ) " : " and xchan_pubforum = 1 ");

	$r = q("select abook_id, xchan_hash, xchan_name, xchan_url, xchan_addr, xchan_photo_s from abook left join xchan on abook_xchan = xchan_hash where xchan_deleted = 0 and abook_channel = %d and abook_pending = 0 and abook_ignored = 0 and abook_blocked = 0 and abook_archived = 0 $sql_extra_1 order by xchan_name",
		intval($uid)
	);

	if(!$r)
		$r = [];

	for($x = 0; $x < count($r); $x ++) {
		if($x3) {
			foreach($x3 as $xx) {
				if($r[$x]['xchan_hash'] == $xx['xchan']) {
					$r[$x]['private_forum'] = 1;
				}
			}
		}

		if($x4) {
			foreach($x4 as $xx) {
				if($r[$x]['xchan_hash'] == $xx['xchan']) {
					$r[$x]['no_post_perms'] = 1;
				}
			}
		}

	}

	App::$data['forum_channels'] = $r;
*/

	return $r;

}

function print_array($arr, $level = 0) {

	$o = EMPTY_STR;
	$tabs = EMPTY_STR;

	if(is_array($arr)) {
		for($x = 0; $x <= $level; $x ++) {
			$tabs .= "\t";
		}
		$o .= '[' . "\n";
		if(count($arr)) {
			foreach($arr as $k => $v) {
				if(is_array($v)) {
					$o .= $tabs . '[' . $k . '] => ' . print_array($v, $level + 1) . "\n";
				}
				else {
					$o .= $tabs . '[' . $k . '] => ' . print_val($v) . ",\n";
				}
			}
		}
		$o .= substr($tabs,0,-1) . ']' . (($level) ? ',' : ';' ). "\n";
		return $o;
	}

}

function print_val($v) {
	if(is_bool($v)) {
		if($v) return 'true';
		return 'false';
	}
	if(is_string($v)) {
		return "'" . $v . "'";
	}
	return $v;

}

function array_path_exists($str,$arr) {

	$ptr = $arr;
	$search = explode('/', $str);

	if($search) {
		foreach($search as $s) {
			if ($ptr && is_array($ptr) && array_key_exists($s,$ptr)) {
				$ptr = $ptr[$s];
			}
			else {
				return false;
			}
		}
		return true;
	}

	return false;

}


/**
 * @brief provide psuedo random token (string) consisting entirely of US-ASCII letters/numbers
 * and with possibly variable length
 *
 * @return string
 */
function new_token($minlen = 36, $maxlen = 48) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890';
    $str   = EMPTY_STR;

    $len   = (($minlen === $maxlen) ? $minlen : mt_rand($minlen, $maxlen));

    for ($a = 0; $a < $len; $a++) {
        $str .= $chars[mt_rand(0, 61)];
    }

    return $str;
}


/**
 * @brief Generate a random v4 UUID.
 *
 * @return string
 */
function new_uuid() {

	try {
		$hash = Uuid::uuid4()->toString();
	} catch (UnableToBuildUuidException $e) {
		$hash = random_string(48);
	}

	return $hash;
}


/**
 * @brief Generate a name-based v5 UUID in the URL namespace
 *
 * @param string $url
 * @return string
 */
function uuid_from_url($url) {

	try {
		$hash = Uuid::uuid5(Uuid::NAMESPACE_URL, $url)->toString();
	} catch (UnableToBuildUuidException $e) {
		$hash = md5($url);
	}
	return $hash;
}

function svg2bb($s) {

	$s = preg_replace("/\<text (.*?)\>(.*?)\<(.*?)\<\/text\>/", '<text $1>$2&lt;$3</text>', $s);
	$s = preg_replace("/\<text (.*?)\>(.*?)\>(.*?)\<\/text\>/", '<text $1>$2&gt;$3</text>', $s);
	$s = preg_replace("/\<text (.*?)\>(.*?)\[(.*?)\<\/text\>/", '<text $1>$2&#91;$3</text>', $s);
	$s = preg_replace("/\<text (.*?)\>(.*?)\](.*?)\<\/text\>/", '<text $1>$2&#93;$3</text>', $s);
	$s = utf8_encode($s);
	$purify = new SvgSanitizer();
	if ($purify->loadXML($s)) {
		$purify->sanitize();
		$output = $purify->saveSVG();
		$output = preg_replace("/\<\?xml(.*?)\>/",'',$output);
		$output = preg_replace("/\<\!\-\-(.*?)\-\-\>/",'',$output);
		$output = str_replace(['<','>'],['[',']'],$output);
		return $output;
	}
	return EMPTY_STR;
}



function serialise($x) {
	return ((is_array($x)) ? 'json:' . json_encode($x) : $x);
}

function unserialise($x) {
	if (is_array($x)) {
		return $x;
	}
	$y = ((substr($x,0,5) === 'json:') ? json_decode(substr($x,5),true) : '');
	return ((is_array($y)) ? $y : $x);
}

function obscurify($s) {
	return str_rot47(base64url_encode($s));
}

function unobscurify($s) {
	return base64url_decode(str_rot47($s));
}

/**
 * @brief Remove new lines and tabs from strings.
 *
 * @return string
 */
function sanitize_text_field($str) {
	return preg_replace('/\s+/S', ' ', $str);
}

/**
 * @brief shortens a string to $max_length without cutting off words
 * @param string $str
 * @param intval $max_length
 * @param string $suffix (optional)

 * @return string
 */
function substr_words($str, $max_length, $suffix = '...') {

	$ret = '';

	if (strlen($str) > $max_length) {
		$words = preg_split('/\s/', $str);
		$i = 0;
		while (true) {
			$length = (strlen($ret) + strlen($words[$i]));
			if ($length > $max_length) {
				break;
			}
			$ret .= " " . $words[$i];
			++$i;
		}
		$ret .= $suffix;
	}

	return (($ret) ? $ret : $str);
}
