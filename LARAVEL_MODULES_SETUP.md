# Laravel API Modular Setup (nwidart/laravel-modules)

Tài liệu mô tả cấu trúc và quy trình setup của `feco_api`, dùng **Laravel 10** + **[nwidart/laravel-modules](https://github.com/nWidart/laravel-modules) v10** để chia API theo domain module. Có thể copy sang project Laravel khác và tái sử dụng.

---

## 1. Tổng quan kiến trúc

```
feco_api/
├── app/                    # Shared core (không chứa business logic theo domain)
├── Modules/                # Mỗi domain = 1 module độc lập
│   ├── Auth/
│   ├── Agent/
│   └── Order/
├── config/modules.php      # Cấu hình nwidart
├── modules_statuses.json   # Bật/tắt module
├── lang/                   # Translation API dùng chung (vi/en)
├── routes/                 # Route Laravel gốc (tối thiểu)
├── database/               # Migration/Seeder dùng chung (users, v.v.)
└── postman.json            # Postman collection đồng bộ với API
```

**Nguyên tắc phân tầng:**

| Tầng | Vị trí | Trách nhiệm |
|------|--------|-------------|
| **Core** | `app/` | User model, middleware, exception handler, base controller, helper dùng chung |
| **Module** | `Modules/{Name}/` | Controller, Request, Service, Model, Route, Enum, Support theo domain |
| **Route API** | `Modules/{Name}/routes/api.php` | Endpoint prefix `api/v1/...` |

Module giao tiếp nhau qua namespace (`Modules\Order\...`, `Modules\Agent\...`), không copy code sang `app/`.

---

## 2. Yêu cầu hệ thống

- PHP `^8.1`
- Laravel `^10.10`
- MySQL (hoặc DB tương thích)
- Composer

**Packages chính:**

```json
{
  "require": {
    "laravel/framework": "^10.10",
    "laravel/sanctum": "^3.3",
    "nwidart/laravel-modules": "^10.0"
  }
}
```

---

## 3. Cài đặt từ project Laravel mới

### Bước 1 — Cài package

```bash
composer require nwidart/laravel-modules
php artisan vendor:publish --provider="Nwidart\Modules\LaravelModulesServiceProvider"
```

Lệnh publish tạo `config/modules.php` và (tuỳ chọn) custom stubs.

### Bước 2 — Cấu hình autoload trong `composer.json`

Thêm namespace `Modules\` trỏ vào thư mục `Modules/`:

```json
"autoload": {
    "psr-4": {
        "App\\": "app/",
        "Modules\\": "Modules/",
        "Database\\Factories\\": "database/factories/",
        "Database\\Seeders\\": "database/seeders/"
    }
}
```

Sau đó:

```bash
composer dump-autoload
```

### Bước 3 — Tạo thư mục Modules

```bash
mkdir Modules
```

### Bước 4 — Tạo module đầu tiên

```bash
php artisan module:make Auth
php artisan module:make Agent
php artisan module:make Order
```

File `modules_statuses.json` ở root sẽ được tạo tự động:

```json
{
    "Auth": true,
    "Agent": true,
    "Order": true
}
```

### Bước 5 — (Tuỳ chọn) Custom stubs

Project có sẵn `stubs/nwidart-stubs/` để override template khi generate module. Bật trong `config/modules.php`:

```php
'stubs' => [
    'enabled' => true,
    'path' => base_path('stubs/nwidart-stubs'),
    // ...
],
```

---

## 4. Cấu trúc thư mục root

```
feco_api/
├── app/
│   ├── Console/
│   ├── Exceptions/
│   │   └── Handler.php              # JSON error cho mọi request /api/*
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Controller.php
│   │   │   └── BaseApiController.php # Chuẩn hoá response JSON
│   │   ├── Kernel.php               # Middleware api group
│   │   └── Middleware/
│   │       ├── AutoRefreshApiToken.php
│   │       └── Authenticate.php
│   ├── Models/
│   │   └── User.php                 # Model dùng chung, HasApiTokens
│   ├── Providers/
│   └── Support/
│       └── AuthTokenIssuer.php      # Cấp access + refresh token
├── Modules/                         # Xem mục 5
├── config/
│   ├── modules.php
│   └── sanctum.php
├── database/
│   ├── factories/
│   ├── migrations/                  # Migration dùng chung (users, sanctum, v.v.)
│   └── seeders/
├── lang/
│   ├── vi/api.php                   # Message API tiếng Việt (mặc định)
│   └── en/api.php
├── modules_statuses.json
├── routes/
│   ├── api.php                      # Route Laravel gốc (tối thiểu)
│   └── web.php
└── postman.json
```

`routes/api.php` gốc gần như trống — toàn bộ endpoint nằm trong từng module.

---

## 5. Cấu trúc một module

Ví dụ module `Order` (module phức tạp nhất):

```
Modules/Order/
├── App/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   └── OrderController.php
│   │   └── Requests/
│   │       ├── StoreOrderRequest.php
│   │       ├── UpdateOrderRequest.php
│   │       └── PreviewOrderRequest.php
│   ├── Mail/
│   ├── Providers/
│   │   ├── OrderServiceProvider.php  # Boot module, bind singleton
│   │   └── RouteServiceProvider.php  # Load routes/api.php + web.php
│   └── Services/
│       ├── OrderPricingService.php
│       └── AgentMonthlyBonusService.php
├── Models/                           # Đặt NGOÀI App/ (convention riêng project)
│   ├── Order.php
│   ├── OrderItem.php
│   └── OrderShipment.php
├── Enums/
│   └── OrderStatus.php
├── Support/                          # Helper/domain logic không phải Service
│   ├── OrderAuditLogger.php
│   └── OrderVatBreakdown.php
├── Database/
│   ├── migrations/                   # Migration riêng module
│   └── Seeders/
│       └── OrderDatabaseSeeder.php
├── routes/
│   ├── api.php
│   └── web.php
├── config/
│   └── config.php
├── resources/
│   └── views/                        # Email templates, v.v.
├── composer.json                     # PSR-4 autoload riêng module
└── module.json                       # Đăng ký ServiceProvider
```

Module đơn giản hơn (`Auth`, `Agent`) có cùng khung nhưng ít thư mục hơn (không có `Services/`, `Enums/`, hoặc `Mail/`).

### 5.1. `module.json`

```json
{
    "name": "Order",
    "alias": "order",
    "description": "",
    "keywords": [],
    "priority": 0,
    "providers": [
        "Modules\\Order\\App\\Providers\\OrderServiceProvider"
    ],
    "files": []
}
```

### 5.2. `composer.json` trong module

Mỗi module có autoload PSR-4 riêng:

```json
{
    "autoload": {
        "psr-4": {
            "Modules\\Order\\": "",
            "Modules\\Order\\App\\": "App/",
            "Modules\\Order\\Database\\Factories\\": "Database/factories/",
            "Modules\\Order\\Database\\Seeders\\": "Database/seeders/"
        }
    }
}
```

**Quy ước namespace:**

| Thư mục | Namespace |
|---------|-----------|
| `App/Http/Controllers/` | `Modules\{Name}\App\Http\Controllers` |
| `App/Http/Requests/` | `Modules\{Name}\App\Http\Requests` |
| `App/Services/` | `Modules\{Name}\App\Services` |
| `Models/` | `Modules\{Name}\Models` |
| `Enums/` | `Modules\{Name}\Enums` |
| `Support/` | `Modules\{Name}\Support` |

### 5.3. ServiceProvider

`{Name}ServiceProvider` chịu trách nhiệm:

- `registerConfig()` — merge config module
- `registerViews()` — load Blade views
- `registerTranslations()` — load lang module (nếu có)
- `loadMigrationsFrom()` — load migration trong `Database/migrations`
- `register(RouteServiceProvider::class)` — đăng ký route
- Bind singleton cho Service class (tuỳ module)

### 5.4. RouteServiceProvider

Mỗi module tự mount route với prefix `api`:

```php
protected function mapApiRoutes(): void
{
    Route::prefix('api')
        ->middleware('api')
        ->namespace($this->moduleNamespace)
        ->group(module_path('Auth', '/routes/api.php'));
}
```

Trong `routes/api.php` của module, dùng prefix version:

```php
Route::prefix('v1/auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    // ...
});

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::get('orders', [OrderController::class, 'index']);
    // ...
});
```

**URL thực tế:** `GET /api/v1/orders`

---

## 6. Cấu hình `config/modules.php` quan trọng

```php
'namespace' => 'Modules',

'paths' => [
    'modules' => base_path('Modules'),
    'migration' => base_path('database/migrations'), // publish migration ra đây (nếu dùng lệnh publish)
    'generator' => [
        'config'       => ['path' => 'config', 'generate' => true],
        'controller'   => ['path' => 'App/Http/Controllers', 'generate' => true],
        'provider'     => ['path' => 'App/Providers', 'generate' => true],
        'routes'       => ['path' => 'routes', 'generate' => true],
        'seeder'       => ['path' => 'Database/Seeders', 'generate' => true],
        'request'      => ['path' => 'App/Http/Requests', 'generate' => false],  // tạo thủ công
        'model'        => ['path' => 'App/Models', 'generate' => false],         // đặt ở Models/ ngoài App/
        'migration'    => ['path' => 'Database/migrations', 'generate' => false],
        // ...
    ],
],

'activator' => 'file',  // trạng thái module lưu ở modules_statuses.json
```

**Lưu ý:** Project tắt auto-generate `model`, `request`, `migration` — tạo thủ công theo convention ở mục 5.

---

## 7. Core layer (`app/`) — những gì dùng chung

### 7.1. `BaseApiController`

Tất cả controller API trong module **extends** `App\Http\Controllers\BaseApiController`:

```php
namespace Modules\Auth\App\Http\Controllers;

use App\Http\Controllers\BaseApiController;

class AuthController extends BaseApiController
{
    public function login(Request $request): JsonResponse
    {
        return $this->successResponse('api.auth.login_success', $data);
    }
}
```

**Format response chuẩn:**

```json
{
    "success": true,
    "message": "Đăng nhập thành công.",
    "data": { }
}
```

```json
{
    "success": false,
    "message": "Dữ liệu không hợp lệ.",
    "errors": { "field": ["..."] },
    "data": {}
}
```

Methods có sẵn: `successResponse()`, `errorResponse()`, `createdResponse()`, `validationErrorResponse()`.

### 7.2. Exception Handler

`app/Exceptions/Handler.php` bắt mọi request `/api/*` và trả JSON thống nhất:

- `401` → `api.auth.invalid_credentials`
- `404` → `api.errors.not_found`
- `500` → `api.errors.unexpected`

### 7.3. Translation

Message key đặt trong `lang/vi/api.php` (mặc định tiếng Việt), tuỳ chọn thêm `lang/en/api.php`.

```php
// lang/vi/api.php
return [
    'auth' => [
        'login_success' => 'Đăng nhập thành công.',
        'invalid_credentials' => 'Thông tin đăng nhập không đúng.',
    ],
    'order' => [
        'store_success' => 'Tạo đơn hàng thành công.',
    ],
];
```

Gọi trong controller: `$this->successResponse('api.auth.login_success', $data)`.

### 7.4. Validation — FormRequest trong module

**Không** validate trực tiếp trong controller. Tạo class tại:

```
Modules/{Name}/App/Http/Requests/{Action}{Resource}Request.php
```

```php
namespace Modules\Order\App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'order_date' => ['required', 'date'],
            // ...
        ];
    }
}
```

Controller chỉ cần:

```php
public function store(StoreOrderRequest $request): JsonResponse
{
    $validated = $request->validated();
    // ...
}
```

---

## 8. Authentication (Laravel Sanctum)

### 8.1. User model

`app/Models/User.php` dùng `Laravel\Sanctum\HasApiTokens`.

### 8.2. Token issuer

`app/Support/AuthTokenIssuer.php` cấp cặp token:

| Token | Ability | Thời hạn |
|-------|---------|----------|
| `api_access` | `access` | 2 giờ |
| `api_refresh` | `refresh` | 30 ngày |

### 8.3. Auto refresh middleware

`app/Http/Middleware/AutoRefreshApiToken.php` nằm trong middleware group `api`:

- Khi access token hết hạn, đọc `X-Refresh-Token` header (hoặc `refresh_token` input)
- Tự động cấp token mới, trả về qua response headers:
  - `X-Access-Token`
  - `X-Refresh-Token`
  - `X-Token-Expires-At`
  - `X-Refresh-Expires-At`

### 8.4. Bảo vệ route

```php
Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    // protected routes
});
```

---

## 9. Quy trình thêm module mới

```bash
# 1. Tạo module
php artisan module:make Product

# 2. Bật module (tự động nếu dùng file activator)
php artisan module:enable Product

# 3. Tạo thủ công các thư mục theo convention
mkdir -p Modules/Product/{Models,Enums,Support,Database/migrations}
mkdir -p Modules/Product/App/Http/{Controllers,Requests}

# 4. Tạo controller
php artisan module:make-controller ProductController Product

# 5. Viết routes/api.php, FormRequest, Model, Service...

# 6. Dump autoload
composer dump-autoload

# 7. Cập nhật postman.json nếu có endpoint mới
```

---

## 10. Cross-module dependency

Module có thể import class từ module khác qua namespace:

```php
use Modules\Order\Enums\OrderStatus;
use Modules\Order\App\Http\Controllers\OrderController;
use Modules\Agent\Models\Agent;
```

**Khuyến nghị:**

- Model domain để trong `Modules/{Name}/Models/`
- User/auth dùng chung → `App\Models\User`
- Tránh logic nghiệp vụ nặng trong controller; đưa vào `App/Services/` hoặc `Support/` của module
- Module `Agent` có thể route tới `OrderController` khi endpoint liên quan đơn hàng con

---

## 11. Artisan commands hữu ích

```bash
# Module
php artisan module:list
php artisan module:make {Name}
php artisan module:enable {Name}
php artisan module:disable {Name}
php artisan module:delete {Name}

# Generate trong module
php artisan module:make-controller {Name}Controller {Module}
php artisan module:make-model {Model} {Module}
php artisan module:make-request {Request} {Module}
php artisan module:make-migration {name} {Module}
php artisan module:make-seeder {Seeder} {Module}

# Migration
php artisan migrate
php artisan module:migrate {Module}
```

---

## 12. API & Postman convention

- Base URL: `{APP_URL}/api/v1/...`
- Mọi thay đổi endpoint (path, method, body, response) phải cập nhật `postman.json` trong cùng PR/task
- Timezone mặc định: `Asia/Ho_Chi_Minh` (`.env.example`)

---

## 13. Checklist khi copy sang project mới

- [ ] `composer require nwidart/laravel-modules`
- [ ] Publish `config/modules.php`
- [ ] Thêm `"Modules\\": "Modules/"` vào `composer.json` autoload
- [ ] Tạo `app/Http/Controllers/BaseApiController.php`
- [ ] Cấu hình `app/Exceptions/Handler.php` cho JSON API
- [ ] Setup Sanctum + `AuthTokenIssuer` + `AutoRefreshApiToken` middleware
- [ ] Tạo `lang/vi/api.php` (và `en` nếu cần)
- [ ] Tạo module đầu tiên: `php artisan module:make Auth`
- [ ] Tuỳ chỉnh `config/modules.php` generator paths
- [ ] (Tuỳ chọn) Copy `stubs/nwidart-stubs/` và bật custom stubs
- [ ] `composer dump-autoload && php artisan module:list`

---

## 14. Modules hiện có trong feco_api

| Module | Alias | Chức năng chính |
|--------|-------|-----------------|
| **Auth** | `auth` | Login, refresh, logout, me, settings |
| **Agent** | `agent` | Quản lý đại lý con, đơn hàng đại lý con |
| **Order** | `order` | CRUD đơn hàng, preview, commission, discount history |

---

## 15. Tham khảo

- [nwidart/laravel-modules docs](https://nwidart.com/laravel-modules/v10/introduction)
- [Laravel Sanctum](https://laravel.com/docs/10.x/sanctum)
- Package version trong project: `nwidart/laravel-modules ^10.0`, Laravel `^10.10`
