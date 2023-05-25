<?php
/**
 *   * Name: default
 *   * Description: Hubzilla 2-column layout with left adside wrapper based on default template by Mario Vavti
 *   * Version: 1
 *   * Author: zlax
 *   * Maintainer: zlax
 *   * ContentRegion: content, region_1
 *   * ContentRegion: right_aside, right_aside_wrapper
 */
?>
<!DOCTYPE html >
<html prefix="og: http://ogp.me/ns#" <?php if(x($page,'color_mode')) echo $page['color_mode'] ?>>
<head>
  <title><?php if(x($page,'title')) echo $page['title'] ?></title>
  <script>var baseurl="<?php echo z_root() ?>";</script>
  <?php if(x($page,'htmlhead')) echo $page['htmlhead'] ?>
</head>
<body <?php if($page['direction']) echo 'dir="rtl"' ?> >
	<?php if(x($page,'banner')) echo $page['banner']; ?>
	<header><?php if(x($page,'header')) echo $page['header']; ?></header>
	<?php if(x($page,'nav')) echo $page['nav']; ?>
	<main>
		<div class="content">
			<div class="columns">
				<section id="region_1"><?php if(x($page,'content')) echo $page['content']; ?>
					<div id="page-footer"></div>
					<div id="pause"></div>
				</section>
				<aside id="region_2" class="d-none d-xl-block"><div class="aside_spacer_top_right"></div><div class="aside_spacer_right"><div id="right_aside_wrapper" class="aside_wrapper"><?php if(x($page,'right_aside')) echo $page['right_aside']; ?></div></div></aside>
			</div>
		</div>
	</main>
	<footer><?php if(x($page,'footer')) echo $page['footer']; ?></footer>
</body>
</html>
