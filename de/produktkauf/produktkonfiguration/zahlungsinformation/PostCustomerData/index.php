<?php
require_once 'config.php';

// Redirect to payments page
header("Location: " . buildPageUrl('payments'));
exit();
?>