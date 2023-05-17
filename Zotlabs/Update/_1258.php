<?php

namespace Zotlabs\Update;

class _1258 {

	function run() {

		dbq("DELETE FROM pconfig WHERE cat = 'channelreputation'");
		dbq("DELETE FROM iconfig WHERE cat = 'channelreputation'");

	}

}
