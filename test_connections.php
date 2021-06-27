<?php
/**
*
*/
defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

require_once(__DIR__."/MoodleRest.php");   				// Moodle REST API driver for PHP
require_once(__DIR__."/cfAutoCollect.inc.php");         // contains cashfree api class
require_once(__DIR__."/webhook/cashfree-webhook.php");  // contains webhook class
echo nl2br("You pressed the button having value: " . $_POST['button'] . "\n");