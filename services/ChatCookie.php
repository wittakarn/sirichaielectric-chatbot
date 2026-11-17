<?php
/** The web chat's conversation id lives only in this HttpOnly cookie. Shared by index.php and call.php. */

const CHAT_COOKIE = 'sirichai_cid';
const CHAT_COOKIE_TTL = 604800; // 7 days

function currentConversationId() {
    $id = isset($_COOKIE[CHAT_COOKIE]) ? $_COOKIE[CHAT_COOKIE] : '';
    return preg_match('/^conv_[0-9]+_[a-f0-9]{9}$/', $id) ? $id : '';
}

function setConversationCookie($id, $expires) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    setcookie(CHAT_COOKIE, $id, array(
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax', // iframe on shop.* is same-site as assistant.*
    ));
}
