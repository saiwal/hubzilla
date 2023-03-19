<?php

if(! App::$install) {

	// Get the UID of the channel owner
	$uid = get_theme_uid();

	if($uid) {
		load_pconfig($uid,'redbasic');
	}

	// Load the owners pconfig
	$nav_bg = get_pconfig($uid, 'redbasic', 'nav_bg');
	$nav_bg_dark = get_pconfig($uid, 'redbasic', 'nav_bg_dark');
	$narrow_navbar = get_pconfig($uid,'redbasic','narrow_navbar');
	$link_color = get_pconfig($uid, 'redbasic', 'link_color');
	$link_color_dark = get_pconfig($uid, 'redbasic', 'link_color_dark');
	$link_hover_color = get_pconfig($uid, 'redbasic', 'link_hover_color');
	$link_hover_color_dark = get_pconfig($uid, 'redbasic', 'link_hover_color_dark');
	$bgcolor = get_pconfig($uid, 'redbasic', 'background_color');
	$bgcolor_dark = get_pconfig($uid, 'redbasic', 'background_color_dark');
	$schema = get_pconfig($uid,'redbasic','schema');
	$background_image = get_pconfig($uid, 'redbasic', 'background_image');
	$background_image_dark = get_pconfig($uid, 'redbasic', 'background_image_dark');
	$font_size = get_pconfig($uid, 'redbasic', 'font_size');
	$radius = get_pconfig($uid, 'redbasic', 'radius');
	$converse_width=get_pconfig($uid,'redbasic','converse_width');
	$top_photo=get_pconfig($uid,'redbasic','top_photo');
	$reply_photo=get_pconfig($uid,'redbasic','reply_photo');
}

// Now load the scheme.  If a value is changed above, we'll keep the settings
// If not, we'll keep those defined by the schema
// Setting $schema to '' wasn't working for some reason, so we'll check it's
// not --- like the mobile theme does instead.

// Allow layouts to over-ride the schema
if (isset($_REQUEST['schema']) && preg_match('/^[\w_-]+$/i', $_REQUEST['schema'])) {
  $schema = $_REQUEST['schema'];
}

if (($schema) && ($schema != '---')) {

	// Check it exists, because this setting gets distributed to clones
	if(file_exists('view/theme/redbasic/schema/' . $schema . '.php')) {
		$schemefile = 'view/theme/redbasic/schema/' . $schema . '.php';
		require_once ($schemefile);
	}

	if(file_exists('view/theme/redbasic/schema/' . $schema . '.css')) {
		$schemecss = file_get_contents('view/theme/redbasic/schema/' . $schema . '.css');
	}

}

// Allow admins to set a default schema for the hub.
// default.php and default.css MUST be symlinks to existing schema files in view/theme/redbasic/schema
if ((!$schema) || ($schema == '---')) {

	if(file_exists('view/theme/redbasic/schema/default.php')) {
		$schemefile = 'view/theme/redbasic/schema/default.php';
		require_once ($schemefile);
	}

	$schemecss = '';
	if(file_exists('view/theme/redbasic/schema/default.css')) {
		$schemecss = file_get_contents('view/theme/redbasic/schema/default.css');
	}

}

//Set some defaults - we have to do this after pulling owner settings, and we have to check for each setting
//individually.  If we don't, we'll have problems if a user has set one, but not all options.
if (! $nav_bg)
	$nav_bg = 'rgba(248, 249, 250, 1)';

if (! $nav_bg_dark)
	$nav_bg_dark = 'rgba(43, 48, 53, 1)';

if (! $link_color)
	$link_color = '#0d6efd';

if (! $link_color_dark)
	$link_color_dark = '#6ea8fe';

if (! $link_hover_color)
	$link_hover_color = '#0a58ca';

if (! $link_hover_color_dark)
	$link_hover_color_dark = '#9ec5fe';

if (! $bgcolor)
	$bgcolor = '#fff';

if (! $bgcolor_dark)
	$bgcolor_dark = '#212529';

if (! $background_image)
	$background_image = '';

if (! $background_image_dark)
	$background_image_dark = '';

if (! $font_size)
	$font_size = '0.875rem';

if (! $radius)
	$radius = '0.375rem';

if (! $converse_width)
	$converse_width = '52'; //unit: rem

if(! $top_photo)
	$top_photo = '2.3rem';

if(! $reply_photo)
	$reply_photo = '2.3rem';

// Apply the settings
if(file_exists('view/theme/redbasic/css/style.css')) {

	$x = file_get_contents('view/theme/redbasic/css/style.css');

	if($narrow_navbar && file_exists('view/theme/redbasic/css/narrow_navbar.css')) {
		$x .= file_get_contents('view/theme/redbasic/css/narrow_navbar.css');
	}

	if($schemecss) {
		$x .= $schemecss;
	}

	$left_aside_width = 22; //unit: rem
	$right_aside_width = 22; //unit: rem

	$main_width = $left_aside_width + $right_aside_width + intval($converse_width);

	// prevent main_width smaller than 768px
	$main_width = (($main_width < 30) ? 30 : $main_width);

	$options = array (
		'$nav_bg' => $nav_bg,
		'$nav_bg_dark' => $nav_bg_dark,
		'$link_color' => $link_color,
		'$link_color_dark' => $link_color_dark,
		'$link_hover_color' => $link_hover_color,
		'$link_hover_color_dark' => $link_hover_color_dark,
		'$bgcolor' => $bgcolor,
		'$bgcolor_dark' => $bgcolor_dark,
		'$background_image' => $background_image,
		'$background_image_dark' => $background_image_dark,
		'$font_size' => $font_size,
		'$radius' => $radius,
		'$converse_width' => $converse_width,
		'$top_photo' => $top_photo,
		'$reply_photo' => $reply_photo,
		'$main_width' => $main_width,
		'$left_aside_width' => $left_aside_width,
		'$right_aside_width' => $right_aside_width
	);

	echo strtr($x, $options);

}

// Set the schema to the default schema in derived themes. See the documentation for creating derived themes how to override this.

if(local_channel() && App::$channel && App::$channel['channel_theme'] != 'redbasic')
	set_pconfig(local_channel(), 'redbasic', 'schema', '---');
