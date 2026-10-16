<?php
/** Snapshot authentication is checked before any cached private content is read. */
require __DIR__ . '/../src/Services/Access.php';
require __DIR__ . '/../src/Services/Startup.php';
class Auth {
    public static $session = null, $user = null;
    public static function currentSession() { return self::$session; }
    public static function findById($id) { return self::$user; }
}
class Store {
    public static $guest = false;
    public static function settings() { return ['auth'=>['require_login'=>!self::$guest]]; }
}
class Catalogue {
    public static $reads = [];
    public static function cachedHome($user) { self::$reads[] = $user['id']; return ['ok'=>true]; }
}
function guest_user() { return ['id'=>'public']; }
function check($condition, $message) {
    if (!$condition) { fwrite(STDERR, "FAIL $message\n"); exit(1); }
}
check(Startup::snapshot()['home'] === null, 'signed out private site');
Store::$guest = true;
check(Startup::snapshot()['home']['ok'], 'guest site');
Auth::$session = ['user_id'=>'a'];
check(Startup::snapshot()['home'] === null, 'deleted account');
Auth::$user = ['id'=>'a','role'=>'user','password_hash'=>'secret','access'=>['mode'=>'pending']];
check(Startup::snapshot()['home'] === null, 'pending account');
Auth::$user['access'] = ['mode'=>'until','until'=>time()-1];
check(Startup::snapshot()['home'] === null, 'expired account');
Auth::$user['access'] = ['mode'=>'unlimited'];
$snapshot = Startup::snapshot();
check($snapshot['home']['ok'], 'active account');
check(!isset($snapshot['me']['user']['password_hash']), 'password excluded');
check(!isset($snapshot['me']['user']['_session']), 'session token excluded');
check(Catalogue::$reads === ['public','a'], 'only authorised identities read cache');
echo "all passed\n";
