<?php /** @file */

require_once("include/network.php");

function system_down() {
// Set $skiplog to true here. Otherwise we will run into a loop
// when system_unavailable() -> system_down() is called from Zotlabs\Lib\Config::Load()
// but the DB is not available.
http_status(503, 'Service Unavailable', true);
echo <<< EOT
<html>
<head><title>System Unavailable</title></head>
<body>
Apologies but this site is unavailable at the moment. Please try again later.
</body>
</html>
EOT;
}
