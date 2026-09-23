# Instagram feed — setup

Powers the "Latest From Instagram" section on the homepage. The frontend
fetches `/api/instagram.php`, which talks to the Instagram Graph API
server-side and returns just the post data (never the access token).

## 1. Get an Instagram access token

The `@dispazio` account needs to be a Professional (Business or Creator)
account. Then, using [Meta's "Instagram API with Instagram Login"
setup](https://developers.facebook.com/docs/instagram-platform/), which
does not require linking a Facebook Page:

1. Create a Meta app at [developers.facebook.com](https://developers.facebook.com/)
   and add the **Instagram** product to it.
2. Under Instagram → API setup with Instagram login, connect the
   `@dispazio` account and generate a **user access token**.
3. Exchange that short-lived token for a **long-lived token** (valid ~60
   days) using Meta's token-exchange endpoint. Long-lived tokens can be
   refreshed before they expire by calling Meta's refresh endpoint — the
   full steps are in Meta's docs linked above, since the exact flow changes
   from time to time.

You only need the **access token** itself for this setup — `INSTAGRAM_USER_ID`
can stay as `"me"` (see below).

## 2. Configure it on Hostinger

In the `api/` folder on the live server (via Hostinger's File Manager or
FTP/SFTP):

1. Copy `config.example.php` to `config.php`, in that same `api/` folder.
2. Open `config.php` and fill in:
   ```php
   define('INSTAGRAM_ACCESS_TOKEN', 'paste your real token here');
   ```
3. Save. `config.php` is git-ignored, so it stays on the server only and
   never gets committed to GitHub.

If your Hostinger plan supports setting custom PHP environment variables
(check hPanel → Advanced → your PHP settings), you can set
`INSTAGRAM_ACCESS_TOKEN` there instead — an environment variable of the
same name always takes priority over `config.php`, so either works.

## 3. Test the endpoint

Once `config.php` is in place, visit `https://yourdomain.com/api/instagram.php`
directly in a browser. You should get JSON like:

```json
{"posts":[{"image":"https://...","caption":"...","permalink":"https://www.instagram.com/p/...","media_type":"IMAGE"}]}
```

- Empty `"posts":[]` with `"error":true` means the token is missing,
  invalid/expired, or Instagram couldn't be reached — recheck `config.php`.
- If you get a PHP error instead of JSON, check the file's permissions and
  your Hostinger PHP error log.

## 4. Deploying from GitHub to Hostinger

GitHub holds `api/instagram.php`, `api/config.example.php`, `api/.htaccess`,
and `api/cache/.htaccess` — never the real token or the runtime cache file.
Deploy however you already deploy this site to Hostinger (Git-based
deploy, FTP, or Hostinger's GitHub integration); `config.php` is not part
of that deploy and, once created directly on the server per step 2, is left
alone by future deploys as long as your deploy method doesn't wipe
untracked files in `api/`.

## 5. Refresh cadence & what happens if Instagram is unavailable

- A successful response is cached on the server (`api/cache/instagram.json`)
  for 25 minutes (`INSTAGRAM_CACHE_TTL_SECONDS` in `config.php`), so most
  visits are served instantly from cache with no Instagram API call at all.
- After the cache expires, the next visit triggers a fresh fetch and
  re-caches it.
- If Instagram is temporarily unreachable, the token expires, or the
  account gets rate-limited, `instagram.php` serves the last known-good
  cached posts instead of failing — visitors never see an error.
- If there is no cache yet at all (e.g. right after first setup, before a
  token is configured) and the API call fails, the endpoint returns
  `{"posts":[],"error":true}`. The frontend never touches the page's
  existing static Instagram cards in that case, so the section keeps
  showing real content either way.
