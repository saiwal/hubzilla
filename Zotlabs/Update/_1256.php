<?php

namespace Zotlabs\Update;

class _1256 {

	function run() {

		dbq("START TRANSACTION");

		$r1 = dbq("TRUNCATE TABLE updates");

		if(ACTIVE_DBTYPE == DBTYPE_POSTGRES) {
			$r2a = dbq("ALTER TABLE updates add ud_update numeric(1) NOT NULL DEFAULT '0'");
			$r2b = dbq("CREATE INDEX ud_update ON updates (ud_update)");

			$r2 = ($r2a && $r2b);
		}

		if(ACTIVE_DBTYPE == DBTYPE_MYSQL) {
			$r2 = dbq("ALTER TABLE updates add ud_update tinyint(1) NOT NULL DEFAULT '0',
				ADD INDEX (ud_update);"
			);
		}

		if($r1 && $r2) {
			dbq("COMMIT");
			return UPDATE_SUCCESS;
		}

		q("ROLLBACK");
		return UPDATE_FAILED;

	}

}
