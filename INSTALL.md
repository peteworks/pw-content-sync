# Content Sync – Installation and updates

## Updates from GitHub

The plugin can update itself from this GitHub repo when you push to the **production** branch.

### For plugin developers (this repo)

1. **Define the repo in wp-config.php** (on sites that should receive updates):

   ```php
   define( 'PW_CONTENT_SYNC_GITHUB_REPO', 'your-username/pw-content-sync' );
   ```

   Use your real GitHub username and repo name.

2. **Release an update:**
   - Bump the `Version` in the plugin header in `pw-content-sync.php` (e.g. `1.0.0` → `1.0.1`).
   - Push to the `production` branch.
   - The GitHub Action builds a zip and creates a release (e.g. `v1.0.1`). No manual release step needed.

3. **First-time setup:** Run `composer install` in the plugin directory so the update checker library is present. The release zip is built in CI with dependencies included.

### For other sites

- Add `define( 'PW_CONTENT_SYNC_GITHUB_REPO', 'your-username/pw-content-sync' );` to wp-config.php.
- **Private repo only:** see [Private repository setup](#private-repository-setup) below.
- Install the plugin (from this repo or from a release zip).
- When you push to `production` and a new release is created, those sites will see an update under **Plugins** and can update with one click.

---

## Private repository setup

If the GitHub repo is **private**, each WordPress site that should receive updates needs a GitHub Personal Access Token so the plugin can read releases and download the zip.

### 1. Create a Personal Access Token (classic)

1. On GitHub: **Settings** (your profile) → **Developer settings** → **Personal access tokens** → **Tokens (classic)**.
2. Click **Generate new token (classic)**.
3. Give it a name (e.g. `Content Sync – [site name]`).
4. Set an expiration (e.g. 90 days or no expiration).
5. Under **Scopes**, check **repo** (full control of private repositories). That’s enough for the update checker.
6. Click **Generate token** and **copy the token** (starts with `ghp_`). You won’t see it again.

### 2. Add the constants to each site

On **every** WordPress site where the plugin is installed and should update from the private repo, add these two lines to **wp-config.php** (above the “That’s all, stop editing!” line):

```php
define( 'PW_CONTENT_SYNC_GITHUB_REPO', 'your-username/pw-content-sync' );
define( 'PW_CONTENT_SYNC_GITHUB_TOKEN', 'ghp_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx' );
```

- Replace `your-username/pw-content-sync` with your actual GitHub username and repo name.
- Replace the second value with the token you generated (keep the quotes).

You can use one token for multiple sites, or create a token per site and revoke it when a site is retired.

### 3. (Optional) Use an environment variable for the token

To avoid storing the token in wp-config.php, set it in your server environment (e.g. in `.env` or your host’s env config), then in wp-config.php:

```php
define( 'PW_CONTENT_SYNC_GITHUB_REPO', 'your-username/pw-content-sync' );
if ( getenv( 'PW_CONTENT_SYNC_GITHUB_TOKEN' ) ) {
	define( 'PW_CONTENT_SYNC_GITHUB_TOKEN', getenv( 'PW_CONTENT_SYNC_GITHUB_TOKEN' ) );
}
```

### 4. Confirm it works

- Save wp-config and load **Plugins** in the admin.
- The plugin should show an update when a newer release exists on GitHub. If it doesn’t, check that the repo name and token are correct and that the token has **repo** scope.
- Pushing to the `production` branch does **not** require a token; the Action uses the built-in `GITHUB_TOKEN` and works the same for public and private repos.
