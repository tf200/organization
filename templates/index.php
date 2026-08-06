<?php
/** @var $l \OCP\IL10N */
/** @var $_ array */

// Nextcloud's layout already provides #content — declaring another one here
// created a duplicate id, and because #content is a flex container the app
// root became a shrink-to-fit flex item 316px narrower than its siblings.
// Same structure as adminpage, superadminpage and employee_dashboard.
//
// `iz-app` lives on App.vue's root rather than here: Vue replaces the mount
// element's contents, so a class set on the server-rendered node it mounts
// into is not a reliable place for it.
?>

<div id="app-content">
	<div id="organization-root"></div>
</div>
