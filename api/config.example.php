<?php
/**
 * Copy this file to "config.php" in this same folder and fill in your real
 * values there.
 *
 * DO NOT commit config.php to GitHub — it's already listed in the project's
 * .gitignore, so a plain `git add .` will skip it. This example file (with
 * blank values) is safe to commit; config.php with real values is not.
 *
 * An environment variable of the same name — set in Hostinger's hPanel, if
 * your plan supports custom PHP environment variables — always overrides
 * the constant below, so you can use whichever your hosting plan allows.
 */

// Required. A long-lived Instagram User Access Token for the @dispazio
// account. Treat this exactly like a password — anyone with it can read
// (and on some scopes, manage) the account's media.
define('INSTAGRAM_ACCESS_TOKEN', '');

// Usually leave as 'me' — that means "the account the access token itself
// belongs to". Only change this if you're using a token that can see
// multiple linked accounts and need to target one specific Instagram
// user/business ID.
define('INSTAGRAM_USER_ID', 'me');

// How many posts to show in the "Latest From Instagram" section.
define('INSTAGRAM_POST_LIMIT', 6);

// How long (in seconds) a successful response is cached before
// instagram.php fetches fresh data from Instagram again. 1500 = 25 minutes.
define('INSTAGRAM_CACHE_TTL_SECONDS', 1500);
