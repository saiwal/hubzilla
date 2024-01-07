<?php
namespace Zotlabs\Update;

use Zotlabs\Lib\Multibase;

class _1261 {
	public function run() {

		$has_sodium = function_exists('sodium_crypto_sign_keypair');
		$has_bcmath = function_exists('bcadd');
		$has_gmp = function_exists('gmp_add');

		if (!$has_sodium) {
			return UPDATE_FAILED;
		}

		if (!($has_gmp || $has_bcmath)) {
			return UPDATE_FAILED;
		}

		dbq("START TRANSACTION");

		$r1 = dbq("ALTER TABLE xchan ADD xchan_epubkey text NOT NULL");

		if(ACTIVE_DBTYPE == DBTYPE_POSTGRES) {
			$r2 = dbq("ALTER TABLE xchan ADD xchan_updated timestamp NOT NULL DEFAULT '0001-01-01 00:00:00'");
		}
		else {
			$r2 = dbq("ALTER TABLE xchan ADD xchan_updated datetime NOT NULL DEFAULT '0001-01-01 00:00:00'");
		}

		$channels = dbq("select * from channel where true");
		if ($channels) {
			foreach ($channels as $channel) {
				$epubkey = (new Multibase())->publicKey($channel['channel_epubkey']);
				q("update xchan set xchan_epubkey = '%s' where xchan_url = '%s'",
					dbesc($epubkey),
					dbesc(channel_url($channel))
				);
			}
		}

		if ($r1 && $r2) {
			dbq("COMMIT");
			return UPDATE_SUCCESS;
		}

		dbq("ROLLBACK");
		return UPDATE_FAILED;

	}

	public function verify() {
		$columns = db_columns('xchan');
		return in_array('xchan_epubkey', $columns) && in_array('xchan_updated', $columns);
	}
}

