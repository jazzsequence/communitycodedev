<?php
/**
 * Bootstrap for unit tests (no WordPress).
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Load the class under test directly — plugins use require_once, not Composer autoload.
require_once dirname( __DIR__ ) . '/includes/crypto.php';
