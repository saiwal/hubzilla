<!DOCTYPE html>
<html data-bs-theme="light">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=0">
	<head>
		<link rel="stylesheet" href="/vendor/twbs/bootstrap/dist/css/bootstrap.min.css" type="text/css" media="screen">
		<link rel="stylesheet" href="/library/fork-awesome/css/fork-awesome.min.css" type="text/css" media="screen">
	</head>
	<body>
		<nav class="navbar bg-body-tertiary">
			<div class="container-sm">
				<span class="navbar-brand"><i class="fa fa-fw fa-hubzilla"></i>{{$header}}</span>
			</div>
		</nav>
		<main class="container-sm mt-4">
			<h5>{{$id}}</h5>
			<form action="totp_check" method="post">
				<input type="hidden" class="form-control" name="totp_code_static" value="1"/>
				<div class="mb-3">
					<label for="totp-input" class="form-label">{{$desc}}</label>
					<input id="totp-input" type="text" class="form-control" name="totp_code" value=""/>
				</div>
				<input type="submit" value="{{$submit}}" class="btn btn-primary">
			</form>
		</main>
	</body>
</html>
