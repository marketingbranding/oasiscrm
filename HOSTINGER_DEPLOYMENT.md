# Hostinger Deployment Guide for Oasis CRM

## Prerequisites

- Hostinger shared hosting or VPS plan (PHP 8.1+ recommended)
- MySQL database (created via Hostinger hPanel)
- Domain pointed to Hostinger nameservers
- SSH access (for VPS) or FTP/SFTP access (for shared hosting)

---

## 1. Prepare Your Application

### Build Frontend Assets
```bash
npm install
npm run build
```

### Optimize for Production
```bash
php artisan optimize
php artisan view:cache
php artisan route:cache
php artisan config:cache
```

---

## 2. Deployment Options

### Option A: Shared Hosting (hPanel / FTP)

1. **Zip your project** (excluding `node_modules`, `vendor`, `.env`, `storage/logs/*`)

2. **Upload via FTP** to `public_html/` on Hostinger

3. **Move contents of `public/` one level up:**
   ```
   public_html/
   ├── assets/          # from public/build/assets
   ├── build/           # from public/build
   ├── index.php        # from public/index.php
   ├── .htaccess        # from public/.htaccess
   ├── favicon.ico
   ├── ...
   └── .. all other Laravel files go inside a subfolder (e.g., oasis-crm) ..
   ```

   Alternative: Set `public_html` as a **symlink** to the `public/` directory.

4. **Set folder permissions:**
   ```bash
   chmod 755 -R storage bootstrap/cache
   chmod 777 -R storage/logs storage/framework storage/app
   ```

5. **Configure `.env`** using Hostinger database credentials

### Option B: VPS (SSH)

```bash
# Clone repository
git clone <your-repo> /var/www/oasis-crm
cd /var/www/oasis-crm

# Install dependencies
composer install --no-dev --optimize-autoloader
npm install && npm run build

# Configure environment
cp .env.example .env
php artisan key:generate

# Set permissions
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache

# Configure Nginx/Apache virtual host pointing to public/
```

---

## 3. Hostinger Database Setup

1. Go to **hPanel → MySQL Databases**
2. Create a new database
3. Create a database user with password
4. Assign the user to the database with **all privileges**
5. Copy credentials to `.env`:
   ```
   DB_CONNECTION=mysql
   DB_HOST=localhost
   DB_PORT=3306
   DB_DATABASE=uXXXXX_yourdbname
   DB_USERNAME=uXXXXX_youruser
   DB_PASSWORD=yourpassword
   ```

---

## 4. Run Migrations & Seeders

```bash
# Via SSH (VPS):
php artisan migrate --force
php artisan db:seed --force

# Via hPanel → PHPMyAdmin:
# Run SQL files from database/migrations manually (not recommended)
```

---

## 5. Hostinger-Specific Notes

### PHP Version
Set PHP to **8.1 or higher** in hPanel → **PHP Configuration**.

### Public Folder
Hostinger's `public_html` is the document root.
Your Laravel `public/` folder must be the web root.

**Recommended shared-hosting structure:**
```
/home/uXXXXX/
├── oasis-crm/              # Laravel app files (app, config, routes, vendor, etc.)
│   └── public/             # Laravel's public folder
└── public_html -> symlink  # or copy contents of oasis-crm/public here
```

### Environment Variables
- Edit `.env` directly via FTP or hPanel File Manager
- Never commit `.env` to Git (already in `.gitignore`)

### Cron Jobs (Task Scheduling)
In hPanel → **Cron Jobs**, add:
```
* * * * * /usr/bin/php /home/uXXXXX/oasis-crm/artisan schedule:run >> /dev/null 2>&1
```

### Queue Worker (if needed)
For production queues, add to cron:
```
* * * * * /usr/bin/php /home/uXXXXX/oasis-crm/artisan queue:work --stop-when-empty >> /dev/null 2>&1
```

---

## 6. Security Checklist

- [ ] `APP_DEBUG=false` in production `.env`
- [ ] `APP_ENV=production`
- [ ] HTTPS enforced via Hostinger SSL (free SSL in hPanel)
- [ ] Strong database password
- [ ] `.env` file outside public web root
- [ ] Storage and bootstrap/cache are writable
- [ ] Regular backups configured

---

## 7. Quick Deploy Script (for VPS)

Save as `deploy.sh`:
```bash
#!/bin/bash
set -e

echo "Deploying Oasis CRM..."
cd /var/www/oasis-crm

git pull origin main
composer install --no-dev --optimize-autoloader
npm install && npm run build
php artisan migrate --force
php artisan optimize
php artisan view:cache
php artisan route:cache
php artisan config:cache

echo "Deployment complete!"
```

---

## Troubleshooting

| Issue | Solution |
|-------|----------|
| White screen after upload | Check `storage/logs/laravel.log` |
| 500 Internal Server Error | Set `APP_DEBUG=true` temporarily to see error |
| Database connection refused | Verify DB credentials in `.env` |
| Assets not loading | Run `npm run build` or check asset URLs |
| Permission denied | Run `chmod -R 775 storage bootstrap/cache` |
