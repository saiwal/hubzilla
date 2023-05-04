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

		Libzotdir::update($xchan, $r[0]['xchan_url'], true, $flag);

		q("UPDATE xchan SET xchan_censored = %d WHERE xchan_hash = '%s'",
			intval($flag),
			dbesc($xchan)
		);

		q("UPDATE xtag SET xtag_flags = %d WHERE xtag_hash = '%s'",
			intval($flag),
			dbesc($xchan)
		);

		if($flag) {
			info( t('Entry censored') . EOL);
		}
		else {
			info( t('Entry OK') . EOL);
		}

		if (isset($_REQUEST['aj'])) {
			json_return_and_die([
				'success' => 1,
				'flag' => $flag
			]);
		}

		goaway(z_root() . '/directory');

	}

}
