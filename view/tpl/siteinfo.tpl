<div class="generic-content-wrapper-styled">
<h2>{{$title}}</h2>
<hr><br/>
<h3>{{$sitenametxt}}</h3> <div>{{$sitename}}</div>

<br/>
<h3>{{$headline}}</h3>

<div>{{if $site_about}}{{$site_about}}{{else}}--{{/if}}</div>
<br>
<h3>{{$admin_headline}}</h3>

<div>{{if $admin_about}}{{$admin_about}}{{else}}--{{/if}}</div>

{{if $addons.1}}
<br>
<h3>{{$addons.0}}</h3>
<ul>
	{{foreach $addons.1 as $addon}}
	<li>{{$addon}}</li>
	{{/foreach}}
</ul>
{{/if}}

{{if $blocked_sites.1}}
<br>
<h3>{{$blocked_sites.0}}</h3>
<ul>
	{{foreach $blocked_sites.1 as $site}}
	<li>{{$site}}</li>
	{{/foreach}}
</ul>
{{/if}}


<br><br>
<div><a href="help/TermsOfService">{{$terms}}</a></div>

<hr>

<h2>{{$prj_header}}</h2>

<div>{{$prj_name}} ({{$z_server_role}})</div>

{{if $prj_version}}
<div>{{$prj_version}}</div>
{{/if}}
<br>

<h3>{{$prj_linktxt}}</h3>
<div>{{$prj_link}}</div>
<br>
<h3>{{$prj_srctxt}}</h3>

<div>{{$prj_src}}</div>

<br><br>
<div>{{$prj_transport}} ({{$transport_link}})</div>

{{if $additional_fed}}
<div>{{$additional_text}} {{$additional_fed}}</div>
{{/if}}

</div>
