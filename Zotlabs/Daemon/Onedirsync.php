<?php /** @file */

namespace Zotlabs\Daemon;

use Zotlabs\Lib\Libzot;
use Zotlabs\Lib\Libzotdir;

class Onedirsync {

	static public function run($argc, $argv) {

		logger('onedirsync: start ' . intval($argv[1]));

		if (($argc > 1) && (intval($argv[1])))
			$update_id = intval($argv[1]);

		if (!$update_id) {
			logger('onedirsync: no update id');
			return;
		}

		$r = q("select * from updates where ud_id = %d",
			intval($update_id)
		);

		if (!$r) {
			logger('onedirsync: update id not found');
			return;
		}

		// ignore doing an update if this ud_addr refers to a known dead hubloc

		$h = q("select * from hubloc where hubloc_id_url = '%s' order by hubloc_id desc",
			dbesc($r[0]['ud_addr']),
		);

		$h = Libzot::zot_record_preferred($h);

		if (($h) && (($h['hubloc_status'] & HUBLOC_OFFLINE) || $h['hubloc_deleted'] || $h['hubloc_error'])) {
			q("update updates set ud_flags = 9 where ud_hash = '%s' and ud_flags != 9",
				dbesc($r[0]['ud_hash'])
			);

			// 2023-04-12: Flag the entry deleted but try to update anyway since the info is not always correct
			// This might change after all directory servers run the new code.

			// return;
		}

		// we might have to pull this out some day, but for now update_directory_entry()
		// runs zot_finger() and is kind of zot specific

		if ($h && $h['hubloc_network'] !== 'zot6') {
			return;
		}

		Libzotdir::update_directory_entry($r[0]);

		return;
	}
}
