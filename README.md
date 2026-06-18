<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework. You can also check out [Laravel Learn](https://laravel.com/learn), where you will be guided through building a modern Laravel application.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Redberry](https://redberry.international/laravel-development)**
- **[Active Logic](https://activelogic.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

## NaraboxTV CDN (Standalone)

### Run Locally

- Set DB to `naraboxtv-cdn` in `.env`.
- **PHP upload limits:** For large uploads (telebot, 2GB files), set in your PHP config: `upload_max_filesize = 2048M` and `post_max_size = 2048M`. See [PHP_UPLOAD_LIMITS.md](PHP_UPLOAD_LIMITS.md).
- Ensure these env keys exist:
  - `CDN_APP_URL=http://127.0.0.1:8000`
  - `FILESYSTEM_DISK=public`
  - `MAX_UPLOAD_MB=2048`
  - `ALLOWED_VIDEO_EXTENSIONS=mp4,mkv,webm,avi,mov,m4v`
- Run:
  - `php artisan migrate`
  - `php artisan storage:link`
  - `php artisan queue:work`
  - `php artisan serve`

### Generate API Token

- Create a token for NaraboxTV server-to-server access:
  - `php artisan cdn:token "naraboxtv-production" --abilities=*`
- The command prints a bearer token once. Save it securely.

### Test Remote Import API

- Request:
  - `POST /api/v1/media/import`
  - Header: `Authorization: Bearer <token>`
  - Body JSON:
    - `source_url` (required)
    - `title` (optional)
    - `asset_type` (`movie|episode|generic`)
- Check status:
  - `GET /api/v1/media/sources/{sourceId}`

### Test Upload API

- Request:
  - `POST /api/v1/media/upload`
  - Header: `Authorization: Bearer <token>`
  - Multipart fields:
    - `file` (required video file)
    - `title` (optional)
    - `asset_type` (optional)
- Response includes `public_url_if_ready` when upload is complete.

### Public Playback URLs

- Ready uploaded/imported sources are exposed as:
  - `/media/{assetId}/{sourceId}/{filename}`
- Streaming endpoint supports `Range` requests for seeking.

### Reconciliation

- Requeue/repair stale imports:
  - `php artisan cdn:reconcile --minutes=30`
- A scheduler entry runs it automatically every ten minutes.
