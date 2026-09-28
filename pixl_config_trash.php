<?php
declare(strict_types=1);

return array (
  'db' => 
  array (
    'host' => '127.0.0.1',
    'database' => 'website_db',
    'user' => 'website_user',
    'password' => 'itallinside0z',
    'charset' => 'utf8mb4',
    'timeout' => 8,
  ),
  'table' => 'pixl_events',
  'site_id' => 'www.bayerchristian.de',
  'allowed_hosts' => 
  array (
    0 => 'bayerchristian.de',
    1 => 'www.bayerchristian.de',
    2 => 'inconsequential.org',
    3 => 'www.inconsequential.org',
    4 => 'localhost',
    5 => '127.0.0.1',
  ),
  'public_key' => '',
  'hash_salt' => 'flfgldhlbfghyeokw8790521',
  'stats_password' => 'myadmnoX',
  'stats_cookie_name' => 'pixl_stats_login',
  'stats_auto_login_days' => 30,
  'stats_urls' => 
  array (
    0 => 'https://www.inconsequential.org/files/0021/home.html',
    1 => 'https://www.inconsequential.org/files/0021/intro/basic/indexs.html',
    2 => 'https://www.inconsequential.org/files/0021/intro/basic/box.html',
    3 => 'https://www.inconsequential.org/files/0021/intro/basic/built.html',
    4 => 'https://www.inconsequential.org/files/0021/intro/basic/free.html',
    5 => 'https://www.inconsequential.org/files/0021/so03d6.html',
  ),
  'geoip' => 
  array (
    'enabled' => true,
    'database_path' => 'data/geoip/dbip-country-lite.mmdb',
    'pushover_country' => true,
    'trust_proxy_headers' => false,
  ),
  'pushover' => 
  array (
    'enabled' => true,
    'token' => 'a8bqct5a78nm595at76x1wrc61rnk4',
    'user' => 'ueVd4X9LwETucvbA8Vcg9J5qZo9447',
    'sound' => 'cashregister',
    'priority' => 0,
    'timeout' => 8,
    'throttle_seconds' => 90,
  ),
);
