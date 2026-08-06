<?php
/** @var $l \OCP\IL10N */
/** @var $_ array */

// `iz-app` is the In Zicht token bridge and the ancestor the .iz-* primitives
// are scoped to. Without it the generic token names (--bg-card, --accent, …)
// are undefined, and an undefined custom property invalidates the whole
// declaration rather than falling back — colours silently vanish.
// App.vue also carries the class on its own root; this is belt and braces for
// anything rendered before Vue mounts. See USING-THE-THEME.md §2.
?>

<div id="content" class="iz-app"></div>
