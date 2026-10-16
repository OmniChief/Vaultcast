<?php
declare(strict_types=1);

/** Only cheap reads belong here: never build a catalogue on the HTML request. */
final class Startup {
    public static function snapshot(): array {
        $session = Auth::currentSession();
        $user = $session ? Auth::findById((string)$session['user_id']) : null;
        $guest = (Store::settings()['auth']['require_login'] ?? true) === false;
        if ($user) {
            unset($user['password_hash'], $user['_session']);
            $user['access_state'] = Access::state($user);
        }
        // Deleted, expired and unapproved accounts must never receive cached library data.
        $allowed = $user ? !empty($user['access_state']['active']) : (!$session && $guest);
        return [
            'me' => ['ok'=>true, 'user'=>$user, 'authenticated'=>(bool)$user, 'guest_browsing'=>$guest],
            'home' => $allowed ? Catalogue::cachedHome($user ?: guest_user()) : null,
        ];
    }
}
