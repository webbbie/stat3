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
    0 => 'www.bayerchristian.de',
    1 => 'bayerchristian.de',
    2 => 'www.inconsequential.org',
    3 => 'inconsequential.org',
  ),
  'stats_urls' => 
  array (
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
    'enabled' => false,
    'token' => '',
    'user' => '',
    'sound' => 'gamelan',
    'priority' => 0,
    'timeout' => 8,
    'throttle_seconds' => 90,
    'max_messages_per_hour' => 10,
    'reading_score_only' => false,
  ),
  'captcha' => [
    'enabled' => false,
    'visitor_interval' => 100,
    'success_target' => 10,
    'max_duration_hours' => 4,
  ],
  'public_key' => '',
  'hash_salt' => 'flfgldhlbfghyeokw8790521',
  'stats_password' => 'myadmno',
  'stats_cookie_name' => 'pixl_stats_login',
  'stats_auto_login_days' => 5,
);
