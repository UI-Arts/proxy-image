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
IMAGES_PICTURE_PLACEHOLDER_MODE=false
IMAGES_RENDERER=gd
IMAGES_LIBVIPS_BINARY=vipsthumbnail
IMAGES_LIBVIPS_MODE=vipsthumbnail
```

Або відредагуй `config/proxy-image.php`.

### Renderer

- `IMAGES_RENDERER=gd` — поточний дефолтний рендерер через GD.
- `IMAGES_RENDERER=libvips` — рендерер через `libvips` CLI.
- `IMAGES_LIBVIPS_MODE=vipsthumbnail|thumbnail_source` — режим роботи libvips.
- `IMAGES_LIBVIPS_BINARY` — шлях/назва бінарника для обраного режиму:
  - `vipsthumbnail` для `IMAGES_LIBVIPS_MODE=vipsthumbnail`
  - `vips` для `IMAGES_LIBVIPS_MODE=thumbnail_source`

### Placeholder mode (picsum)

Коли `IMAGES_PICTURE_PLACEHOLDER_MODE=true`, методи `ProxyImage::picture(...)` і `ProxyImage::singleUrl(...)` віддають лінки на `https://picsum.photos/{width}/{height}`.
Розміри беруться з `sizes` для кожного брейкпоінта. Якщо висота `a`, використовується `width / 2`.
Це зменшує навантаження на локальну машину під час розробки.

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

## Приклад Використання

```
{!! 
\UIArts\ProxyImage\Facades\ProxyImage::picture(
    pictures: 'uploads/image.jpg',
    sizes: [
        'mobile' => [300, 'a'], // [(int)width, (int|string)height] height - може бути або int або стрінг, але тільки "a", що означає auto
        'tablet' => [300, 'a'],
        'desktop' => [800, 'a'],
    ],
    class: 'picture-wrap', // class для <picture>
    attributes: [
        'mode' => 'fit', // fit, fill
        'quality' => 85,
        'densities' => [1, 2],
        'alt' => 'Some alt',
        'title' => 'Some title',
        'class' => 'img-fluid', // class для <img>
        'data-zoom' => 'uploads/image.jpg',
        'data-error-src' => 'uploads/image.jpg',
        'loading' => 'lazy',
        'fetchPriority' => 'high', // high, low, auto
    ],
)
!!}
```

## Headless JSON (picture data)

Структуровані дані для `<picture>` можна отримати без HTML:

```php
\UIArts\ProxyImage\Facades\ProxyImage::pictureData(
    pictures: 'uploads/image.jpg',
    sizes: [
        'mobile' => [300, 'a'],
        'tablet' => [300, 'a'],
        'desktop' => [800, 'a'],
    ],
    attributes: [
        'mode' => 'fit',
        'quality' => 85,
        'densities' => [1, 2],
        'formats' => ['avif', 'jpg'],
        'alt' => 'Some alt',
    ],
);
```

Формат відповіді:

```php
[
    'mode' => 'proxy', // proxy|bypass|placeholder
    'img' => [
        'src' => '/i/...',
        'srcset' => '/i/... 1x, /i/... 2x',
        'alt' => 'Some alt',
        'title' => 'Some title',
        'width' => 800,
        'height' => 'auto',
    ],
    'sources' => [
        [
            'type' => 'image/avif',
            'media' => '(min-width: 1200px)',
            'srcset' => '/i/... 1x, /i/... 2x',
        ],
    ],
]
```

Приклад для Vue:

```vue
<template>
  <picture v-if="data?.img?.src">
    <source
      v-for="(source, i) in (data?.sources || [])"
      :key="i"
      :type="source.type || undefined"
      :media="source.media || undefined"
      :srcset="source.srcset || ''"
    />
    <img
      :src="data.img.src"
      :srcset="data.img.srcset || undefined"
      :alt="data.img.alt || ''"
      :title="data.img.title || undefined"
      :width="data.img.width || undefined"
      :height="data.img.height !== 'auto' ? data.img.height : undefined"
    >
  </picture>
</template>

<script setup>
defineProps({
  data: { type: Object, required: true }
});
</script>
```
