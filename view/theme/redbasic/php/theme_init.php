<?php
require_once('view/php/theme_init.php');

head_add_css('/library/fork-awesome/css/fork-awesome.min.css');
head_add_css('/vendor/twbs/bootstrap/dist/css/bootstrap.min.css');
head_add_css('/library/bootstrap-tagsinput/bootstrap-tagsinput.css');
head_add_css('/view/css/bootstrap-red.css');
head_add_css('/library/bootstrap-colorpicker/dist/css/bootstrap-colorpicker.min.css');

head_add_js('/vendor/twbs/bootstrap/dist/js/bootstrap.bundle.min.js');
head_add_js('/library/bootbox/bootbox.min.js');
head_add_js('/library/bootstrap-tagsinput/bootstrap-tagsinput.js');
head_add_js('/library/bootstrap-colorpicker/dist/js/bootstrap-colorpicker.js');

$redbasic_mode = '';
$redbasic_navbar_mode = '';

if (local_channel()) {
	$redbasic_mode = ((get_pconfig(local_channel(), 'redbasic', 'dark_mode')) ? 'dark' : 'light');
	$redbasic_navbar_mode = ((get_pconfig(local_channel(), 'redbasic', 'navbar_dark_mode')) ? 'dark' : 'light');
}

if (App::$profile_uid) {
	$redbasic_mode = ((get_pconfig(App::$profile_uid, 'redbasic', 'dark_mode')) ? 'dark' : 'light');
	$redbasic_navbar_mode = ((get_pconfig(App::$profile_uid, 'redbasic', 'navbar_dark_mode')) ? 'dark' : 'light');
}

if (!$redbasic_mode) {
	$redbasic_mode = ((get_config('redbasic', 'dark_mode')) ? 'dark' : 'light');
	$redbasic_navbar_mode = ((get_config('redbasic', 'navbar_dark_mode')) ? 'dark' : 'light');
}

App::$page['color_mode'] = 'data-bs-theme="' . $redbasic_mode . '"';
App::$page['navbar_color_mode'] = (($redbasic_navbar_mode === 'dark') ? 'data-bs-theme="' . $redbasic_navbar_mode . '"' : '');
