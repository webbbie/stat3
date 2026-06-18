# Security

## Secrets

Never commit `pixl_config.php`. It contains database credentials, the dashboard password, and the hash salt.

Use `pixl_config.example.php` as the GitHub-safe template.

## Production Checklist

- Set a strong `stats_password`.
- Set a long random `hash_salt`.
- Restrict `allowed_hosts`.
- Use HTTPS.
- Keep PHP and MySQL updated.
- Do not expose database credentials in web server error pages.

## Dashboard Auth

The dashboard supports plain text `stats_password` values and hashed values generated with PHP `password_hash()`.

Example:

```php
echo password_hash('your-password', PASSWORD_DEFAULT);
```

Then paste the generated hash into `stats_password`.
