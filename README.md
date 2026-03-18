# Proxy Image Laravel Package

Пакет для проксування та обробки зображень (resize, crop, optimize) через підписані URL.

---

## Встановлення

### 1. Додати пакет через Composer

```bash
composer require uiarts/proxy-image
```

---

### 2. Опублікувати конфіг

```bash
php artisan vendor:publish --tag=proxy-image-config
```

---

### 3. Опублікувати entrypoint (images.php)

```bash
php artisan vendor:publish --tag=proxy-image-public
```

Після цього файл буде доступний:

```
public/images.php
```

---

## Налаштування

В `.env` додай:

```env
IMAGES_HMAC_SECRET=your-secret-key
IMAGES_DEFAULT_DISK=your_disk
IMAGES_LOCAL_DISK=your_disk
IMAGES_LOCAL_PREFIX=
IMAGES_S3_DISK=s3
IMAGES_S3_PREFIX=
```

Або відредагуй `config/proxy-image.php`.

---

## Налаштування Nginx

Додай у конфіг:

```nginx
location ^~ /i/ {
    include fastcgi_params;

    fastcgi_pass php-fpm:9000;

    fastcgi_param SCRIPT_FILENAME $document_root/images.php;
    fastcgi_param SCRIPT_NAME /images.php;
    fastcgi_param REQUEST_URI $request_uri;
    fastcgi_param PHP_VALUE "error_log=/var/log/nginx/application_php_errors.log";
    add_header X-Images-Entrypoint 1 always;
}

if (!-e $request_filename) {
    rewrite ^.*$ /index.php last;
}
```

---

## Формат URL

```
/i/{signature}/{ops}/{encoded}.{ext}
```

---

## Приклад URL

```
https://example.com/i/{signature}/w:800/dXBsb2Fkcy90ZXN0LmpwZw.jpg
```

