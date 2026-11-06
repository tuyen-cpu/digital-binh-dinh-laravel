# Bản đồ du lịch Bình Định — Laravel API

Backend Laravel 13 / PHP 8.3+, chuyển từ Django REST trong thư mục cha.
Dùng database mới, không nhập database, tài khoản hoặc media metadata từ Django.
Các danh mục và cấu hình trang mặc định được lấy từ hằng số trong source, không phải dữ liệu người dùng.
`php artisan db:seed` nạp 7 danh mục, cấu hình trang và 165 địa điểm từ file seed Django
`datasourc/locations.json` (bản sao độc lập tại `database/seeders/locations.json`).
Seed thêm bản ghi còn thiếu theo ID, giữ các chỉnh sửa và dữ liệu tự thêm khi chạy lại.
Seed không tạo tài khoản hoặc đổi mật khẩu admin; tài khoản admin cấu hình trong `.env`.

## Chạy trên máy hiện tại

Từ thư mục gốc `digital-map-python1` trong PowerShell:

```powershell
.\laravel.ps1 migrate --seed
.\laravel.ps1 serve --host=127.0.0.1 --port=8001
```

Helper tự tìm PHP portable tại `.tools/php/php.exe`, sau đó tìm PHP trong PATH.
Thư mục `.tools/` và `vendor/` không đưa vào Git. Máy khác cần cài PHP và Composer.
API: `http://127.0.0.1:8001/api`; health: `http://127.0.0.1:8001/up`.
Không cần chạy npm hoặc build giao diện cho backend này.

## Cài đặt ở máy khác

Yêu cầu PHP 8.3+ với cURL, DOM/XML, Fileinfo, Mbstring, OpenSSL, PDO và driver database.
Môi trường đã kiểm tra: PHP 8.4, SQLite. Có cấu hình PostgreSQL/MySQL của Laravel nhưng chưa kiểm thử hai database này.

```powershell
cd laravel
composer install
Copy-Item .env.example .env
php artisan key:generate
New-Item -ItemType File -Path database/database.sqlite -Force
php artisan migrate --seed
php artisan serve --host=127.0.0.1 --port=8001
```

Tạo file SQLite chỉ trong lần cài mới. Không tạo đè database đã sử dụng.
Hoặc dùng `composer run setup` cho lần cài mới (tạo APP_KEY mới).

## Cấu hình

Sửa `laravel/.env`, đây là file riêng với `.env` của Django:

- `APP_KEY`: sinh bằng `php artisan key:generate`, dùng ký JWT. Giữ ổn định khi triển khai.
- `APP_URL`: URL backend. Đổi URL hoặc APP_KEY sẽ khiến JWT cũ không còn hợp lệ.
- `ADMIN_USERNAME`, `ADMIN_PASSWORD`, `ADMIN_DISPLAY_NAME`: tài khoản admin hệ thống.
  `ADMIN_PASSWORD` mặc định trống; cần tự đặt mật khẩu trước khi đăng nhập admin.
- `DEFAULT_RESET_PASSWORD`: để trống sẽ sinh mật khẩu ngẫu nhiên khi admin reset tài khoản.
- `CORS_ALLOWED_ORIGINS`: danh sách origin frontend, phân tách bằng dấu phẩy.
- `JWT_ACCESS_TOKEN_LIFETIME_MINUTES=60`, `JWT_REFRESH_TOKEN_LIFETIME_DAYS=7`.
- `R2_ACCOUNT_ID`, `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`, `R2_BUCKET_NAME`, `R2_PUBLIC_URL`.
  Có thể đặt `R2_ENDPOINT_URL` để ghi đè endpoint được tạo từ account ID.
- `LOCATIONS_SEED_PATH`: file JSON tùy chọn cho thao tác admin reset địa điểm.
  Mặc định dùng `database/seeders/locations.json` với 165 địa điểm như Django.
  Thao tác API reset xóa địa điểm rồi khôi phục seed; khác với `db:seed` giữ dữ liệu đã sửa.

Với PostgreSQL mới: đặt `DB_CONNECTION=pgsql`, `DB_HOST`, `DB_PORT=5432`,
`DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, rồi chạy `php artisan migrate --seed`.
Không trỏ Laravel vào database Django cũ.
Khi lên production đặt `APP_DEBUG=false`, cấu hình document root vào `laravel/public`,
và cho ứng dụng quyền ghi `storage` cùng `bootstrap/cache`.

## Tương thích API với frontend

Giữ `/api`, tên trường camelCase, cấu trúc danh sách `{count, results}` và lỗi validation HTTP 400.
Các route có thể gọi với hoặc không có dấu `/` cuối URL.
Frontend đổi base URL sang backend Laravel và đăng nhập lại để nhận JWT mới.
Các endpoint thực tế trong source Django được giữ lại; một số method PATCH được bổ sung.

| Nhóm | Endpoint chính |
| --- | --- |
| Auth | `POST /auth/register`, `POST /auth/login`, `POST /auth/logout`, `GET /auth/me`, `PUT/PATCH /auth/profile`, `POST /auth/change-password` |
| User | `GET /admin/users`, `PUT/DELETE /admin/users/{username}`, `POST /admin/users/{username}/reset-password` |
| Danh mục | `GET/POST /categories`, `GET/PUT/PATCH/DELETE /categories/{key}`, `POST /categories/seed` |
| Địa điểm | `GET/POST /locations`, `GET/PUT/PATCH/DELETE /locations/{id}`, `POST /locations/import`, `POST /locations/reset` |
| Đánh giá | `GET /reviews`, `GET/POST /locations/{id}/reviews`, `DELETE /reviews/{id}`, `POST /reviews/{id}/replies`, `DELETE /reviews/{id}/replies/{reply}` |
| Yêu thích | `GET /favorites`, `POST /favorites/toggle` |
| Hành trình | `GET/POST /travel/history`, `DELETE /travel/history/{id}` |
| Lịch nhắc | `GET/POST /travel/reminders`, `PATCH/DELETE /travel/reminders/{id}` |
| Thống kê | `GET/POST/DELETE /analytics/events` |
| Cấu hình | `GET/PUT/PATCH /site-config`, `POST /site-config/reset` |
| Media | `POST /media/upload`, `POST /media/discard`, `POST /media/delete` |

Header xác thực: `Authorization: Bearer <access>`.
Đăng nhập/đăng ký trả `{access, refresh, user}`.
Chưa có endpoint refresh token trong backend Django gốc; Laravel cũng chưa bổ sung.
Logout thu hồi cả access và refresh của phiên đăng nhập hiện tại.
Mật khẩu và token cũ không được chuyển.

Danh sách địa điểm giữ cách lọc theo `category` và `q` của source hiện tại;
chỉ trả địa điểm active, không phân trang để frontend tải toàn bộ lên bản đồ.
Manager được quản lý địa điểm theo category/group/subgroup đã cấp, tạo địa điểm theo category.
Manager xem analytics theo category giống Django; danh sách review theo cả ba phạm vi.
Xóa review yêu cầu quyền quản lý địa điểm; chủ review không tự xóa nếu không có quyền quản lý,
đúng với logic Django hiện tại. Chủ reply có thể xóa reply của mình.
Các thao tác với tài khoản admin hệ thống được bảo vệ.

Upload dùng multipart với `file` và `folder`.
User thường chỉ upload `avatars`; manager dùng `locations/gallery/panoramas/videos/avatars`;
`slides/logos` chỉ dành cho admin. MIME được kiểm tra từ nội dung file,
giới hạn ảnh 10 MB, video 40 MB. Cấu hình PHP `upload_max_filesize=40M`, `post_max_size=45M`
và giới hạn request của web server tương ứng.
Media/delete chỉ nhận key UUID do ứng dụng tạo; URL bên ngoài không được xóa.

## Vòng đời media

Upload tạo metadata `pending`. Lưu nội dung thành công chuyển media thành `attached`
trong cùng transaction. Đổi/xóa ảnh đưa media cũ vào `orphaned`; chỉ xóa sau commit,
kiểm tra lại toàn bộ tham chiếu ở địa điểm, avatar và cấu hình trang.
Lỗi R2 giữ metadata để thử lại. Discard chỉ dọn pending thuộc người upload.

```powershell
php artisan media:cleanup --hours=24 --dry-run
php artisan media:cleanup --hours=24
php artisan schedule:work
```

Production: scheduler gọi `php artisan schedule:run` mỗi phút.
Tác vụ dọn pending quá 24 giờ và orphaned chạy mỗi giờ.
Lịch nhắc du lịch được lưu qua API; backend này không gửi email/SMS/push,
giống chức năng backend Django hiện tại.

## Kiểm tra

```powershell
# Tại thư mục gốc
.\laravel.ps1 test --compact

# Hoặc trong laravel/, khi PHP đã có trong PATH
php artisan test --compact
php artisan route:list --path=api --except-vendor
vendor/bin/pint --dirty --format agent
```

Bộ test chạy trên SQLite in-memory và storage giả lập, bao gồm auth/JWT, phân quyền,
CRUD, review, ownership, hành trình, analytics, cấu hình, upload MIME, media dùng chung,
rollback và lỗi R2. Chưa xác minh upload bằng bucket thật hoặc tích hợp frontend vì source frontend
không nằm trong checkout này. Xem `routes/api.php` và `tests/Feature/` để đối chiếu chi tiết.
