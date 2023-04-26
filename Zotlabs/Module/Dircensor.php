<?php

namespace Zotlabs\Module;

use App;
use Zotlabs\Web\Controller;


class Dircensor extends Controller {

	function get() {
		if(! is_site_admin()) {
			return;
		}

		$dirmode = intval(get_config('system','directory_mode'));

		if(!in_array($dirmode, [DIRECTORY_MODE_PRIMARY, DIRECTORY_MODE_SECONDARY, DIRECTORY_MODE_STANDALONE])) {
			return;
		}

		$xchan = argv(1);
		if(! $xchan) {
			return;
		}

		$r = q("select * from xchan where xchan_hash = '%s'",
			dbesc($xchan)
		);

		if(! $r) {
			return;
		}

		$severity = intval($_REQUEST['severity'] ?? 0);

		if ($severity < 0) {
			$severity = 0;
		}

		if ($severity > 2) {
			$severity = 2;
		}

		q("update xchan set xchan_censored = %d where xchan_hash = '%s'",
			intval($severity),
			dbesc($xchan)
		);

		if($severity) {
			info( t('Entry censored') . EOL);
		}
		else {
			info( t('Entry uncensored') . EOL);
		}

		goaway(z_root() . '/directory');

	}

}
