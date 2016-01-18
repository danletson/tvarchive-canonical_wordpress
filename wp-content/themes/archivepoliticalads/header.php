<?php
// 	Use the proper DOCTYPE.
// The opening <html> tag should include language_attributes().
// The <meta> charset element should be placed before everything else, including the <title> element.
// Use bloginfo() to set the <meta> charset and description elements.
// Use wp_title() to set the <title> element. See why.
// Use Automatic Feed Links to add feed links.
// Add a call to wp_head() before the closing </head> tag. Plugins use this action hook to add their own scripts, stylesheets, and other functionality.
// Do not link the theme stylesheets in the Header template. Use the wp_enqueue_scripts action hook in a theme function instead.
// Here's an example of a correctly-formatted HTML5 compliant head area:
?>
    <!DOCTYPE html>
    <html <?php language_attributes(); ?>>

    <head>
        <meta charset="<?php bloginfo( 'charset' ); ?>" />
        <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">

        <title>
            <?php wp_title(); ?>
        </title>
        <link rel="profile" href="http://gmpg.org/xfn/11" />
        <link rel="pingback" href="<?php bloginfo( 'pingback_url' ); ?>" />
        <?php wp_head(); ?>
    </head>

    <body>
        <div class="container-fluid">
            <div id="header" class="row">
                <div id="branding" class="col-xs-8 col-sm-6 col-md-5 col-lg-6">
                        <a href="<?php bloginfo('url'); ?>/">
                            <img class="hidden-xs" id="logo" src="<?php bloginfo('template_directory'); ?>/img/brand_logo.png" srcset="<?php bloginfo('template_directory'); ?>/img/brand_logo@2x.png 2x, <?php bloginfo('template_directory'); ?>/img/brand_logo@3x.png 3x" />
                            <div id="title"><span id="title-political">Political</span><span id="title-ad"> TV Ad</span> <span id="title-library">Archive</span></div>
                        </a>
                </div>
                <div class="col-xs-4 col-sm-6 col-md-7 col-lg-6">
                    <nav class="navbar navbar-default">
                        <button type="button" id="menu-icon" class="navbar-toggle" data-toggle="collapse" data-target="#mainNav-collapse" aria-expanded="false">
                            <span class="sr-only">Toggle navigation</span>
                            <span class="icon-bar"></span>
                            <span class="icon-bar"></span>
                            <span class="icon-bar"></span>
                        </button>
                       <!--  <a href="#" ></a> -->
                        <div class="collapse navbar-collapse navigation no-transition" id="mainNav-collapse">
                            <ul>
                                <li><a href="<?php bloginfo('url'); ?>/about">about</a></li>
                                <li><a href="<?php bloginfo('url'); ?>/blog">blog</a></li>
                                <li><a href="<?php bloginfo('url'); ?>/data">data download</a></li>
                                <li><a href="<?php bloginfo('url'); ?>/resources">resources</a></li>
                                <li><a href="<?php bloginfo('url'); ?>/browse">search</a></li>
                            </ul>
                         </div>
                    </nav>
                </div>
            </div>
