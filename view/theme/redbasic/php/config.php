<?php

namespace Zotlabs\Theme;

class RedbasicConfig {

	function get_schemas() {
		$files = glob('view/theme/redbasic/schema/*.php');

		$scheme_choices = [];

		if($files) {

			if(in_array('view/theme/redbasic/schema/default.php', $files)) {
				$scheme_choices['---'] = t('Default');
				$scheme_choices['focus'] = t('Focus (Hubzilla default)');
			}
			else {
				$scheme_choices['---'] = t('Focus (Hubzilla default)');
			}

			foreach($files as $file) {
				$f = basename($file, ".php");
				if($f != 'default') {
					$scheme_name = $f;
					$scheme_choices[$f] = $scheme_name;
				}
			}
		}

		return $scheme_choices;
	}

	function get() {
		if(! local_channel()) {
			return;
		}

		$arr = array();
		$arr['dark_mode'] = get_pconfig(local_channel(),'redbasic', 'dark_mode');
		$arr['navbar_dark_mode'] = get_pconfig(local_channel(),'redbasic', 'navbar_dark_mode');
		$arr['narrow_navbar'] = get_pconfig(local_channel(),'redbasic', 'narrow_navbar' );
		$arr['nav_bg'] = get_pconfig(local_channel(),'redbasic', 'nav_bg' );
		$arr['nav_bg_dark'] = get_pconfig(local_channel(),'redbasic', 'nav_bg_dark' );
		$arr['link_color'] = get_pconfig(local_channel(),'redbasic', 'link_color' );
		$arr['link_color_dark'] = get_pconfig(local_channel(),'redbasic', 'link_color_dark' );
		$arr['link_hover_color'] = get_pconfig(local_channel(),'redbasic', 'link_hover_color' );
		$arr['link_hover_color_dark'] = get_pconfig(local_channel(),'redbasic', 'link_hover_color_dark' );
		$arr['bgcolor'] = get_pconfig(local_channel(),'redbasic', 'background_color' );
		$arr['bgcolor_dark'] = get_pconfig(local_channel(),'redbasic', 'background_color_dark' );
		$arr['background_image'] = get_pconfig(local_channel(),'redbasic', 'background_image' );
		$arr['background_image_dark'] = get_pconfig(local_channel(),'redbasic', 'background_image_dark' );
		$arr['font_size'] = get_pconfig(local_channel(),'redbasic', 'font_size' );
		$arr['radius'] = get_pconfig(local_channel(),'redbasic', 'radius' );
		$arr['converse_width']=get_pconfig(local_channel(),"redbasic","converse_width");
		$arr['top_photo']=get_pconfig(local_channel(),"redbasic","top_photo");
		$arr['reply_photo']=get_pconfig(local_channel(),"redbasic","reply_photo");
		$arr['advanced_theming'] = get_pconfig(local_channel(), 'redbasic', 'advanced_theming');
		return $this->form($arr);
	}

	function post() {
		if(!local_channel()) {
			return;
		}

		if (isset($_POST['redbasic-settings-submit'])) {
			set_pconfig(local_channel(), 'redbasic', 'narrow_navbar', $_POST['redbasic_narrow_navbar']);
			set_pconfig(local_channel(), 'redbasic', 'navbar_dark_mode', $_POST['redbasic_navbar_dark_mode']);
			set_pconfig(local_channel(), 'redbasic', 'dark_mode', $_POST['redbasic_dark_mode']);
			set_pconfig(local_channel(), 'redbasic', 'nav_bg', $_POST['redbasic_nav_bg']);
			set_pconfig(local_channel(), 'redbasic', 'nav_bg_dark', $_POST['redbasic_nav_bg_dark']);
			set_pconfig(local_channel(), 'redbasic', 'link_color', $_POST['redbasic_link_color']);
			set_pconfig(local_channel(), 'redbasic', 'link_color_dark', $_POST['redbasic_link_color_dark']);
			set_pconfig(local_channel(), 'redbasic', 'link_hover_color', $_POST['redbasic_link_hover_color']);
			set_pconfig(local_channel(), 'redbasic', 'link_hover_color_dark', $_POST['redbasic_link_hover_color_dark']);
			set_pconfig(local_channel(), 'redbasic', 'background_color', $_POST['redbasic_background_color']);
			set_pconfig(local_channel(), 'redbasic', 'background_color_dark', $_POST['redbasic_background_color_dark']);
			set_pconfig(local_channel(), 'redbasic', 'background_image', $_POST['redbasic_background_image']);
			set_pconfig(local_channel(), 'redbasic', 'background_image_dark', $_POST['redbasic_background_image_dark']);
			set_pconfig(local_channel(), 'redbasic', 'font_size', $_POST['redbasic_font_size']);
			set_pconfig(local_channel(), 'redbasic', 'radius', $_POST['redbasic_radius']);
			set_pconfig(local_channel(), 'redbasic', 'converse_width', $_POST['redbasic_converse_width']);
			set_pconfig(local_channel(), 'redbasic', 'top_photo', $_POST['redbasic_top_photo']);
			set_pconfig(local_channel(), 'redbasic', 'reply_photo', $_POST['redbasic_reply_photo']);
			set_pconfig(local_channel(), 'redbasic', 'advanced_theming', $_POST['redbasic_advanced_theming']);
		}
	}

	function form($arr) {

		$expert = false;
		if(get_pconfig(local_channel(), 'redbasic', 'advanced_theming')) {
			$expert = true;
		}

	  	$o = replace_macros(get_markup_template('theme_settings.tpl'), array(
			'$submit' => t('Submit'),
			'$baseurl' => z_root(),
			'$theme' => \App::$channel['channel_theme'],
			'$expert' => $expert,
			'$title' => t("Theme settings"),
			'$dark' => t('Dark style'),
			'$light' => t('Light style'),
			'$common' => t('Common settings'),
			'$dark_mode' => array('redbasic_dark_mode',t('Default to dark mode'),$arr['dark_mode'], '', array(t('No'),t('Yes'))),
			'$navbar_dark_mode' => array('redbasic_navbar_dark_mode',t('Always use light icons for navbar'),$arr['navbar_dark_mode'], t('Enable this option if you use a dark navbar color in light mode'), array(t('No'),t('Yes'))),
			'$narrow_navbar' => array('redbasic_narrow_navbar',t('Narrow navbar'),$arr['narrow_navbar'], '', array(t('No'),t('Yes'))),
			'$nav_bg' => array('redbasic_nav_bg', t('Navigation bar background color'), $arr['nav_bg']),
			'$nav_bg_dark' => array('redbasic_nav_bg_dark', t('Dark navigation bar background color'), $arr['nav_bg_dark']),
			'$link_color' => array('redbasic_link_color', t('Link color'), $arr['link_color']),
			'$link_color_dark' => array('redbasic_link_color_dark', t('Dark link color'), $arr['link_color_dark']),
			'$link_hover_color' => array('redbasic_link_hover_color', t('Link hover color'), $arr['link_hover_color']),
			'$link_hover_color_dark' => array('redbasic_link_hover_color_dark', t('Dark link hover color'), $arr['link_hover_color_dark']),
			'$bgcolor' => array('redbasic_background_color', t('Set the background color'), $arr['bgcolor']),
			'$bgcolor_dark' => array('redbasic_background_color_dark', t('Set the dark background color'), $arr['bgcolor_dark']),
			'$background_image' => array('redbasic_background_image', t('Set the background image'), $arr['background_image']),
			'$background_image_dark' => array('redbasic_background_image_dark', t('Set the dark background image'), $arr['background_image_dark']),
			'$font_size' => array('redbasic_font_size', t('Set font-size for the entire application'), $arr['font_size'], t('Examples: 1rem, 100%, 16px')),
			'$radius' => array('redbasic_radius', t('Set radius of corners'), $arr['radius'], t('Example: 4px')),
			'$converse_width' => array('redbasic_converse_width',t('Set maximum width of content region in rem'),$arr['converse_width'], t('Leave empty for default width')),
			'$top_photo' => array('redbasic_top_photo', t('Set size of conversation author photo'), $arr['top_photo']),
			'$reply_photo' => array('redbasic_reply_photo', t('Set size of followup author photos'), $arr['reply_photo']),
			'$advanced_theming' => ['redbasic_advanced_theming', t('Show advanced settings'), $arr['advanced_theming'], '', [t('No'), t('Yes')]]
			));

		return $o;
	}

}

function redbasic_theme_admin_enable() {
	// This function is called once when the theme is being enabled by the admin
	// It can be used to register hooks etc.
}

function redbasic_theme_admin_disable() {
	// This function is called once when the theme is being disabled by the admin
	// It can be used to unregister hooks etc.
}




