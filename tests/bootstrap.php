<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap.
 *
 * `nextcloud/ocp` is a stubs-only package for static analysis — it declares no
 * autoload, so the real OCP\* classes have to come from a running Nextcloud
 * server. Without loading base.php first, every test that mocks an OCP
 * interface dies with 'Class or interface "OCP\IConfig" does not exist'.
 *
 * That means the suite runs inside the Nextcloud container, not on the host:
 *
 *   docker exec -w /var/www/html/apps-shared/organization master-nextcloud-1 \
 *       vendor/bin/phpunit
 *
 * Override the server root with NEXTCLOUD_ROOT if your layout differs.
 */

$serverRoot = getenv('NEXTCLOUD_ROOT') ?: '/var/www/html';
$base = $serverRoot . '/lib/base.php';

if (!is_file($base)) {
	fwrite(STDERR, sprintf(
		"Cannot find the Nextcloud server at %s.\n"
		. "These tests need the server's autoloader for the OCP classes.\n"
		. "Run them inside the container, or set NEXTCLOUD_ROOT.\n",
		$serverRoot,
	));
	exit(1);
}

require_once $base;
require_once __DIR__ . '/../vendor/autoload.php';
