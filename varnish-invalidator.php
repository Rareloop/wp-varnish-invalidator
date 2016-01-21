<?php
/*
Plugin Name: Varnish Invalidator
Description: Invalidates objects from a Varnish Cache
Version: 1.0.0
Author: Rareloop
Author URI: http://www.rareloop.com
*/

// If we haven't loaded this plugin from Composer we need to add our own autoloader
if (!class_exists('Rareloop\VarnishInvalidator')) {
    $autoloader = require_once('autoload.php');
    $autoloader('Rareloop\\', __DIR__ . '/src/Rareloop/');
}
