<?php

/**
 *   * Name: Categories
 *   * Description: Display a menu with links to categories
 *   * Requires: channel, articles, cards, cloud
 */

namespace Zotlabs\Widget;

use App;
use Zotlabs\Lib\Apps;

require_once('include/contact_widgets.php');

class Categories {

	function widget($arr) {

		$files = ((array_key_exists('files',$arr) && $arr['files']) ? true : false);

		if(!isset(App::$profile['profile_uid']) || !perm_is_allowed(App::$profile['profile_uid'], get_observer_hash(), 'view_stream')) {
			return '';
		}

		$cat = ((x($_REQUEST, 'cat')) ? htmlspecialchars($_REQUEST['cat'], ENT_COMPAT, 'UTF-8') : '');

		// Discard queries from the current URL, as the template expects a base
		// URL without any queries.
		$base = substr(App::$query_string, 0, strcspn(App::$query_string, '?'));

		if($files) {
			return filecategories_widget($base, $cat);
		}

		return categories_widget($base, $cat);

	}
}
