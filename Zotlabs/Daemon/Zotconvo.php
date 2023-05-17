<?php

namespace Zotlabs\Daemon;

use Zotlabs\Lib\Libzot;

class Zotconvo {

	static public function run($argc, $argv) {

		logger('Zotconvo invoked: ' . print_r($argv, true));

		if ($argc != 3) {
			return;
		}

		$mid = $argv[2];
		if (!$mid) {
			return;
		}

		$channel = channelx_by_n(intval($argv[1]));
		if (!$channel) {
			return;
		}

		Libzot::fetch_conversation($channel, $mid);

		return;

	}
}
