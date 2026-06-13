# URL Structure Documentation

## Clean URLs Overview

The `.htaccess` file has been configured to provide clean, SEO-friendly URLs without `.php` extensions.

## URL Transformations

### Before (Old URLs)
```
https://yourdomain.com/index.php
https://yourdomain.com/vertical.php
https://yourdomain.com/admin/racers.php
https://yourdomain.com/stats.php
```

### After (Clean URLs)
```
https://yourdomain.com/
https://yourdomain.com/vertical
https://yourdomain.com/admin/racers
https://yourdomain.com/stats
```

## Complete URL Map

### Main Pages
| Clean URL | Actual File | Description |
|-----------|-------------|-------------|
| `/` | `index.php` | Main leaderboard |
| `/stats` | `stats.php` | Power rankings & analytics |
| `/rivalries` | `rivalries.php` | Head-to-head matchups |
| `/archive` | `archive.php` | Historical data |
| `/all-time` | `all_time.php` | All-time standings |
| `/cup-stats` | `cup_stats.php` | Cup statistics |
| `/add-result` | `add_result.php` | Add race results form |
| `/season-archives` | `season_archives.php` | Season history |

### Display Screens (Semantic Routes)
| Clean URL | Actual File | Display Type |
|-----------|-------------|--------------|
| `/display/vertical` | `vertical.php` | 2048x2560 signage |
| `/display/horizontal` | `horizontal.php` | 1920x1080 TV |
| `/display/auto-vertical` | `auto-vertical.php` | Auto-rotating vertical |

### Admin Panel
| Clean URL | Actual File | Purpose |
|-----------|-------------|---------|
| `/admin/racers` | `admin/racers.php` | Racer management |
| `/admin/seasons` | `admin/seasons.php` | Season configuration |
| `/admin/results` | `admin/results_manage.php` | Results management |

### API Endpoints (RESTful Style)
| Clean URL | Actual File | Purpose |
|-----------|-------------|---------|
| `/api/recap` | `api/gemini_recap.php` | AI recap generation |
| `/api/season-report` | `api/generate_season_report.php` | Season report generation |

### Dynamic Routes

#### Season-Specific Pages
```
/season/s4           → index.php?season=s4
/season/s5           → index.php?season=s5
```

#### Future: Racer Profiles (Commented Out)
```
/racer/1             → racer_profile.php?id=1
/racer/25            → racer_profile.php?id=25
```

Uncomment line 68 in `.htaccess` to enable racer profile URLs.

## Legacy URL Handling

Old URLs automatically redirect to new clean URLs with a **301 Permanent Redirect**:

```
/index.php          → 301 Redirect → /
/vertical.php       → 301 Redirect → /vertical
/admin/racers.php   → 301 Redirect → /admin/racers
```

This ensures:
- **SEO preservation** - Search engines update their indexes
- **Bookmarks work** - Old bookmarks redirect automatically
- **No broken links** - All old URLs continue to function

## Security Features

### Protected Resources
The following are **blocked** and return `403 Forbidden`:

```
/private/            (all files in private directory)
/private/config/     (database credentials)
/.git/               (version control files)
/.env                (environment variables)
*.log                (log files)
*.db                 (database files)
*.sqlite             (SQLite databases)
*.ini                (config files)
*.md                 (markdown documentation - optional)
```

### Hidden Files
All files starting with `.` are protected:
```
/.gitignore
/.htaccess
/.env
```

## Performance Optimizations

### Browser Caching
Static assets are cached aggressively:

- **Images** (PNG, JPG, GIF, SVG): 1 year
- **CSS & JavaScript**: 1 month
- **Fonts**: 1 year

This means browsers won't re-download Mario Kart character images on every page load.

### Gzip Compression
Text-based files are compressed before sending to browsers:
- HTML, CSS, JavaScript
- JSON API responses
- XML files

This reduces bandwidth usage by ~70-80% for text content.

## Security Headers

The `.htaccess` adds security headers to all responses:

| Header | Purpose |
|--------|---------|
| `X-Frame-Options: SAMEORIGIN` | Prevents clickjacking attacks |
| `X-XSS-Protection: 1; mode=block` | Browser XSS protection |
| `X-Content-Type-Options: nosniff` | Prevents MIME sniffing |
| `Referrer-Policy: strict-origin-when-cross-origin` | Controls referer information |

## PHP Configuration

If your hosting allows `.htaccess` PHP settings:

```apache
expose_php = Off                      # Hide PHP version
session.cookie_httponly = 1           # Prevent XSS session theft
session.cookie_samesite = Lax         # CSRF protection
```

## Error Handling

### 404 Not Found
Any non-existent URL redirects to the homepage:
```
/nonexistent-page  →  /  (index.php)
```

### Custom Error Pages (Optional)
Uncomment lines 114-115 in `.htaccess` to enable custom error pages:
```apache
ErrorDocument 403 /error/403.html
ErrorDocument 500 /error/500.html
```

## Testing Your URLs

### Test Clean URLs
```bash
# Should return homepage
curl -I https://yourdomain.com/

# Should return stats page
curl -I https://yourdomain.com/stats

# Should show 301 redirect
curl -I https://yourdomain.com/stats.php
```

### Test Security
```bash
# Should return 403 Forbidden
curl -I https://yourdomain.com/private/config/config.php

# Should return 403 Forbidden
curl -I https://yourdomain.com/.git/config
```

### Test Caching
```bash
# Check cache headers
curl -I https://yourdomain.com/assets/css/global.css

# Should show: Cache-Control: max-age=2592000 (1 month)
```

## Updating Internal Links

You can now update your internal links to use clean URLs:

### Before
```php
<a href="/admin/racers.php">Manage Racers</a>
<a href="/vertical.php">Vertical Display</a>
```

### After (Optional, but cleaner)
```php
<a href="/admin/racers">Manage Racers</a>
<a href="/display/vertical">Vertical Display</a>
```

**Note:** Both versions work! The old URLs redirect to clean ones automatically.

## Browser Cache Busting

If you update CSS/JS and want users to see changes immediately:

1. **Option A: Query Parameters** (doesn't break caching)
   ```html
   <link rel="stylesheet" href="/assets/css/global.css?v=2">
   ```

2. **Option B: Rename Files** (permanent cache)
   ```
   global.css → global.v2.css
   ```

## Troubleshooting

### Clean URLs Not Working?

1. **Check if mod_rewrite is enabled**
   ```php
   <?php phpinfo(); ?>
   // Search for "mod_rewrite"
   ```

2. **Check .htaccess is being read**
   - Add a syntax error to `.htaccess`
   - If you get a 500 error, it's working
   - Remove the syntax error

3. **Check AllowOverride setting**
   - Your hosting must allow `.htaccess` overrides
   - Contact hosting support if URLs aren't rewriting

### 500 Internal Server Error?

- Check Apache error logs
- Some shared hosts don't allow certain directives
- Try commenting out sections 6-9 (caching, headers, PHP settings)

## Future Enhancements

### Racer Profile URLs
Uncomment line 68 to enable:
```apache
RewriteRule ^racer/([0-9]+)$ racer_profile.php?id=$1 [L,QSA]
```

Then create `racer_profile.php` to handle individual racer pages.

### Subdomain for Displays
Consider pointing displays to a subdomain:
```
https://display.yourdomain.com/vertical
https://display.yourdomain.com/horizontal
```

### API Versioning
For future API changes:
```apache
RewriteRule ^api/v2/recap$ api/v2/gemini_recap.php [L]
```

## Summary

Your URLs are now:
- ✅ **Clean** - No `.php` extensions
- ✅ **Secure** - Private files protected
- ✅ **Fast** - Caching and compression enabled
- ✅ **SEO-friendly** - Semantic URLs with redirects
- ✅ **Backward compatible** - Old URLs still work

Enjoy your clean URL structure! 🏎️
