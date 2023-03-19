{{include file="field_checkbox.tpl" field=$dark_mode}}
{{include file="field_checkbox.tpl" field=$narrow_navbar}}
{{include file="field_input.tpl" field=$converse_width}}
{{include file="field_input.tpl" field=$font_size}}
{{include file="field_checkbox.tpl" field=$advanced_theming}}

{{if $expert}}
	<h3>{{$light}}</h3>
	{{include file="field_checkbox.tpl" field=$navbar_dark_mode}}
	{{include file="field_colorinput.tpl" field=$nav_bg}}
	{{include file="field_colorinput.tpl" field=$bgcolor}}
	{{include file="field_colorinput.tpl" field=$background_image}}
	{{include file="field_colorinput.tpl" field=$link_color}}
	{{include file="field_colorinput.tpl" field=$link_hover_color}}

	<h3>{{$dark}}</h3>
	{{include file="field_colorinput.tpl" field=$nav_bg_dark}}
	{{include file="field_colorinput.tpl" field=$bgcolor_dark}}
	{{include file="field_colorinput.tpl" field=$background_image_dark}}
	{{include file="field_colorinput.tpl" field=$link_color_dark}}
	{{include file="field_colorinput.tpl" field=$link_hover_color_dark}}

	<h3>{{$common}}</h3>
	{{include file="field_input.tpl" field=$radius}}
	{{include file="field_input.tpl" field=$top_photo}}
	{{include file="field_input.tpl" field=$reply_photo}}

<script>
	$(function(){
		$('#id_redbasic_link_color, #id_redbasic_link_color_dark, #id_redbasic_link_hover_color, #id_redbasic_link_hover_color_dark, #id_redbasic_background_color, #id_redbasic_background_color_dark, #id_redbasic_nav_bg, #id_redbasic_nav_bg_dark').colorpicker({format: 'rgba'});
	});
</script>
{{/if}}
<div class="settings-submit-wrapper" >
	<button type="submit" name="redbasic-settings-submit" class="btn btn-primary">{{$submit}}</button>
</div>
