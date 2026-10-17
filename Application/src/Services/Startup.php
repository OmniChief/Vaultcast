<?php
declare(strict_types=1);

/** Only cheap reads belong here: never build a catalogue on the HTML request. */
final class Startup {
    public static function snapshot(): array {
        $session = Auth::currentSession();
        $user = $session ? Auth::findById((string)$session['user_id']) : null;
        $guest = NetworkAccess::guestAllowed();
        if ($user) {
            unset($user['password_hash'], $user['_session']);
            $user['access_state'] = Access::state($user);
        }
        // Deleted, expired and unapproved accounts must never receive cached library data.
        $allowed = $user ? !empty($user['access_state']['active']) : (!$session && $guest);
        $page = $allowed ? FirstPage::home($user ?: guest_user()) : ['home'=>null, 'refresh'=>false];
        if ($page['home'] !== null) $page['home'] = AreaAccess::home($page['home'], $user);
        return [
            'me' => ['ok'=>true, 'user'=>$user, 'authenticated'=>(bool)$user, 'guest_browsing'=>$guest],
            'home' => $page['home'],
            'refresh' => $page['refresh'],
            'serverRendered' => true,
        ];
    }
}
