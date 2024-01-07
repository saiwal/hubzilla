<?php
namespace Zotlabs\Update;

class _1260 {
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

		$r1 = dbq("ALTER TABLE channel ADD channel_epubkey text NOT NULL DEFAULT ''");
		$r2 = dbq("ALTER TABLE channel ADD channel_eprvkey text NOT NULL DEFAULT ''");

		$channels = dbq("select channel_id from channel where true");
		if ($channels) {
			foreach ($channels as $channel) {
				$keys = sodium_crypto_sign_keypair();
				$pubkey = sodium_bin2base64(sodium_crypto_sign_publickey($keys), SODIUM_BASE64_VARIANT_ORIGINAL_NO_PADDING);
				$prvkey = sodium_bin2base64(sodium_crypto_sign_secretkey($keys), SODIUM_BASE64_VARIANT_ORIGINAL_NO_PADDING);
				q("update channel set channel_epubkey = '%s', channel_eprvkey = '%s' where channel_id = %d",
					dbesc($pubkey),
					dbesc($prvkey),
					intval($channel['channel_id'])
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
		$columns = db_columns('channel');
		return in_array('channel_epubkey', $columns) && in_array('channel_eprvkey', $columns);
	}
}

