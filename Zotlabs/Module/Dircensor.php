<?php

namespace Zotlabs\Module;

use App;
use Zotlabs\Web\Controller;
use Zotlabs\Lib\Libzotdir;


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
		$flag = DIRECTORY_FLAG_OK;

		if ($severity === 1) {
			$flag = DIRECTORY_FLAG_UNSAFE;
		}

		if ($severity === 2) {
			$flag = DIRECTORY_FLAG_HIDDEN;
		}
hz_syslog('flag: '. print_r($flag, true));
		Libzotdir::update($xchan, $r[0]['xchan_url'], true, $flag);

		q("update xchan set xchan_censored = %d where xchan_hash = '%s'",
			intval($flag),
			dbesc($xchan)
		);

		if($flag) {
			info( t('Entry censored') . EOL);
		}
		else {
			info( t('Entry OK') . EOL);
		}

		goaway(z_root() . '/directory');

	}

}
