<?php
declare(strict_types=1);

final class FactoryReset
{
    public static function run(array $body): array
    {
        $actor = Auth::requireAdmin();
        if (($body['confirmation'] ?? '') !== 'RESET VAULTCAST') throw new DomainException('Type RESET VAULTCAST to confirm.');
        $existing = Auth::findById($actor['id']);
        if (!$existing || !password_verify((string)($body['password'] ?? ''), $existing['password_hash'] ?? '')) throw new DomainException('Administrator password is incorrect.');
        \Vaultcast\Database::lockForReset();
        $existing=Auth::findById($actor['id']);
        if (!$existing || ($existing['role'] ?? '')!=='admin' || !password_verify((string)($body['password'] ?? ''),$existing['password_hash'] ?? '')) throw new DomainException('Administrator access changed. Sign in again before resetting.');
        require_once STREAM_ROOT . '/src/Installer.php';
        // New identity prevents old browser outboxes from restoring erased personal data.
        $admin = ['id'=>bin2hex(random_bytes(16)), 'username'=>$existing['username'], 'display_name'=>$existing['username'], 'role'=>'admin', 'password_hash'=>$existing['password_hash'], 'prefs'=>['theme'=>'dark','autoplay'=>false]];
        $settings = \Vaultcast\Installer::defaults([]);
        \Vaultcast\Database::transaction(static function($pdo) use ($admin, $settings): void {
            // DELETE is transactional and works with the installer's restricted DB account.
            foreach (['vaultcast_chat_files','vaultcast_chat_messages','vaultcast_chat_members','vaultcast_chat_rooms','vaultcast_chat_presence','vaultcast_sessions','vaultcast_playback_tokens','vaultcast_progress','vaultcast_metadata_pins','vaultcast_media_facts','vaultcast_items','vaultcast_users','vaultcast_cache','vaultcast_documents'] as $table) $pdo->exec('DELETE FROM `' . $table . '`');
            $insert = $pdo->prepare('INSERT INTO vaultcast_documents (name,payload) VALUES (?,?)');
            foreach (['schema_version'=>1, 'settings'=>$settings, 'users'=>[], 'catalogue'=>['sections'=>[], 'scanned_at'=>null, 'item_count'=>0]] as $name=>$value) $insert->execute([$name, \Vaultcast\Repository::encode($value)]);
            $insert = $pdo->prepare('INSERT INTO vaultcast_users (id,username,password_hash,role,payload) VALUES (?,?,?,?,?)');
            $insert->execute([$admin['id'],$admin['username'],$admin['password_hash'],'admin',\Vaultcast\Repository::encode($admin)]);
        });
        \Vaultcast\Repository::cacheClear();
        Chat::resetFiles();
        Store::invalidate();
        Catalogue::invalidateDerivedCaches();
        Auth::createSession($admin);
        return ['ok'=>true, 'redirect'=>\Vaultcast\Database::basePath() . 'settings/sections'];
    }
}
