<?php

/**
 *   * Name: Redbasic
 *   * Description: Hubzilla standard theme
 *   * Version: 2.2
 *   * MinVersion: 8.0
 *   * MaxVersion: 9.0
 *   * Author: Fabrixxm
 *   * Maintainer: Mike Macgirvin
 *   * Maintainer: Mario Vavti
 *   * Theme_Color: rgb(248, 249, 250)
 *   * Background_Color: rgb(254,254,254)
 */

use App;

function redbasic_init(&$a) {

	$mode = '';

	if (local_channel()) {
		$mode = ((get_pconfig(local_channel(), 'redbasic', 'dark_mode')) ? 'dark' : 'light');
	}

	if (App::$profile_uid) {
		$mode = ((get_pconfig(App::$profile_uid, 'redbasic', 'dark_mode')) ? 'dark' : 'light');
	}

	if (!$mode) {
		$mode = ((get_config('redbasic', 'dark_mode')) ? 'dark' : 'light');
	}

	App::$page['color_mode'] = 'data-bs-theme="' . $mode . '"';
}
