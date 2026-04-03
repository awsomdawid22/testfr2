# Los Angeles Experience Forum - Setup Guide

## Requirements
- PHP 8.0+
- MySQL 5.7+ / MariaDB 10.3+
- Apache or Nginx with mod_rewrite
- PHP extensions: PDO, PDO_MySQL, GD (for image handling)

## Installation

### 1. Set Up the Database
```sql
-- Run the database.sql file:
mysql -u root -p < database.sql
```

### 2. Configure the Application
Edit `includes/config.php`:
```php
define('DB_HOST', 'localhost');
define('DB_USER', 'your_database_user');
define('DB_PASS', 'your_secure_password');
define('DB_NAME', 'lae_forum');
define('SITE_URL', 'http://yourdomain.com');  // No trailing slash
define('SECRET_KEY', 'your_64_char_random_secret_key_here');
```

### 3. Set File Permissions
```bash
chmod 755 assets/img/
mkdir -p assets/img/avatars
chmod 777 assets/img/avatars/
```

### 4. Web Server Configuration

**Apache (.htaccess) - place in root:**
```apache
RewriteEngine On
RewriteBase /
Options -Indexes
```

**Nginx:**
```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
location ~ \.php$ {
    fastcgi_pass php-fpm;
    include fastcgi_params;
}
```

### 5. Default Admin Account
After importing the database:
- **Username:** `LAE_Admin`
- **Password:** `Admin@123`

⚠️ **Change the password immediately after first login!**

## File Structure
```
lae-forum/
├── includes/
│   ├── config.php          # Database & site config
│   ├── auth.php            # Login/register/session functions
│   ├── functions.php       # Helper utilities
│   ├── header.php          # HTML header & nav
│   └── footer.php          # HTML footer
├── admin/
│   ├── index.php           # Dashboard
│   ├── users.php           # User management
│   ├── roles.php           # Roles & permissions
│   ├── applications.php    # Staff applications
│   ├── appeals.php         # Ban appeals
│   ├── moderate.php        # Quick mod actions
│   ├── audit.php           # Audit log
│   └── sidebar.php         # Admin nav sidebar
├── assets/
│   ├── css/main.css        # All styles
│   ├── js/main.js          # Frontend JS
│   └── img/avatars/        # User avatar uploads
├── index.php               # Homepage
├── forum.php               # Forum category & thread list
├── thread.php              # Thread view & replies
├── new-thread.php          # Create new thread
├── login.php               # Login page
├── register.php            # Registration page
├── logout.php              # Logout handler
├── profile.php             # User profile
├── settings.php            # Account settings
├── staff.php               # Staff team page
├── rules.php               # Server rules page
├── apply.php               # Staff application form
├── notifications.php       # Notifications page
├── api.php                 # AJAX API (likes, etc)
└── database.sql            # Complete database schema
```

## Roles (Default Hierarchy)
| Role | Priority | Can Post | Can Moderate | Can Admin | Can Ban |
|------|----------|----------|--------------|-----------|---------|
| Owner | 100 | ✓ | ✓ | ✓ | ✓ |
| Co-Owner | 90 | ✓ | ✓ | ✓ | ✓ |
| Head Admin | 80 | ✓ | ✓ | ✓ | ✓ |
| Administrator | 70 | ✓ | ✓ | ✓ | ✗ |
| Senior Moderator | 60 | ✓ | ✓ | ✗ | ✗ |
| Moderator | 50 | ✓ | ✓ | ✗ | ✗ |
| Senior Developer | 45 | ✓ | ✗ | ✗ | ✗ |
| Developer | 40 | ✓ | ✗ | ✗ | ✗ |
| VIP+ | 25 | ✓ | ✗ | ✗ | ✗ |
| VIP | 20 | ✓ | ✗ | ✗ | ✗ |
| Trusted | 15 | ✓ | ✗ | ✗ | ✗ |
| Member | 10 | ✓ | ✗ | ✗ | ✗ |
| New Member | 5 | ✓ | ✗ | ✗ | ✗ |
| Banned | 0 | ✗ | ✗ | ✗ | ✗ |

## Features
- ✅ Full forum with categories, threads, posts
- ✅ BBCode editor (bold, italic, quotes, code blocks, images, links)
- ✅ Role system with full permission control
- ✅ Admin dashboard with stats
- ✅ User management (ban, unban, role change, delete)
- ✅ Staff application system with review workflow
- ✅ Ban appeal system
- ✅ Audit log for all mod/admin actions
- ✅ Post likes via AJAX
- ✅ User profiles with post/thread history
- ✅ Account settings & avatar upload
- ✅ Notifications system
- ✅ CSRF protection on all forms
- ✅ Prepared statements (SQL injection prevention)
- ✅ Pagination throughout
- ✅ Thread pinning/locking
- ✅ Post hiding (soft delete)
- ✅ Online user tracking
- ✅ Staff team page
- ✅ Server rules page

## Security Notes
- All DB queries use PDO prepared statements
- Passwords hashed with bcrypt (cost 12)
- CSRF tokens on every form
- XSS prevention via htmlspecialchars throughout
- Session regeneration on login
- HTTP-only session cookies
