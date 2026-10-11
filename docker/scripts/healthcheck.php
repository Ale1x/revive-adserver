<?php
if (PHP_SAPI !== 'cli' || count($argv) !== 3) {
    exit(2);
}
chdir('/var/www/html');
require 'init.php';
require_once 'lib/OA/DB.php';
require_once 'lib/OX/Plugin/Component.php';
if (!defined('OA_INSTALLATION_STATUS') ||
    OA_INSTALLATION_STATUS !== OA_INSTALLATION_STATUS_INSTALLED ||
    $GLOBALS['_MAX']['CONF']['database']['name'] !== $argv[2]) {
    exit(1);
}
$connection = OA_DB::singleton();
if (PEAR::isError($connection)) {
    exit(1);
}
$result = $connection->queryOne('SELECT 1');
if (PEAR::isError($result) || (string) $result !== '1' ||
    empty($GLOBALS['_MAX']['CONF']['plugins']['openXBannerTypes']) ||
    empty($GLOBALS['_MAX']['CONF']['plugins']['openXInvocationTags']) ||
    empty(OX_Component::getComponents('invocationTags')) ||
    !is_readable(MAX_PATH . '/plugins/bannerTypeHtml/oxHtml/genericHtml.delivery.php') ||
    !is_writable(MAX_PATH . '/var/cache') ||
    !is_writable(MAX_PATH . '/www/images')) {
    exit(1);
}
