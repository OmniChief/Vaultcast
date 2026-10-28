<?php
declare(strict_types=1);

final class ContributionPoints
{
    private static function account(string $id): string { return 'contribution-account-'.hash('sha256',$id); }
    public static function summary(string $id): array {
        $value=\Vaultcast\Repository::document(self::account($id),[]);
        return array_replace(['balance'=>0,'earned'=>0,'spent'=>0,'admin_added'=>0,'images_generated'=>0,'videos_converted'=>0],is_array($value) ? $value : []);
    }
    private static function apply(string $key,string $id,int $amount,string $kind,string $actor='',string $note=''): bool {
        return \Vaultcast\Database::transaction(static function(PDO $pdo) use($key,$id,$amount,$kind,$actor,$note): bool {
            // The unique event and account update commit together, including when composed with a job update.
            $event=['user_id'=>$id,'amount'=>$amount,'kind'=>$kind,'reference'=>$key,'actor'=>$actor,'note'=>$note,'created_at'=>time()];
            $insert=$pdo->prepare('INSERT IGNORE INTO vaultcast_documents(name,payload) VALUES (?,?)');
            $insert->execute(['contribution-event-'.hash('sha256',$key),json_encode($event,JSON_THROW_ON_ERROR)]);
            if ($insert->rowCount()===0) return false;
            $name=self::account($id);
            $insert->execute([$name,'{}']);
            $read=$pdo->prepare('SELECT payload FROM vaultcast_documents WHERE name=? FOR UPDATE'); $read->execute([$name]);
            $account=json_decode((string)$read->fetchColumn(),true) ?: [];
            $account=array_replace(['balance'=>0,'earned'=>0,'spent'=>0,'admin_added'=>0,'images_generated'=>0,'videos_converted'=>0],$account);
            if ($account['balance']+$amount<0) throw new DomainException('Not enough contribution points. Each image costs 2 points. Contribute compute or ask an administrator to add points.');
            $account['balance']+=$amount;
            if ($kind==='image_cost') $account['spent']+=abs($amount);
            if ($kind==='image_refund') $account['spent']=max(0,$account['spent']-$amount);
            if (in_array($kind,['image_reward','conversion_reward'],true)) $account['earned']+=$amount;
            if ($kind==='image_reward') $account['images_generated']++;
            if ($kind==='conversion_reward') $account['videos_converted']++;
            if ($kind==='admin_grant') $account['admin_added']+=$amount;
            \Vaultcast\Repository::putDocument($name,$account);
            return true;
        });
    }
    public static function chargeImage(string $user,string $job): void { self::apply('image-cost:'.$job,$user,-2,'image_cost'); }
    public static function refundImage(array $job): void {
        if ((int)($job['points_cost'] ?? 0)===2) self::apply('image-refund:'.$job['id'],(string)$job['user_id'],2,'image_refund');
    }
    public static function rewardImage(string $user,string $job): void { self::apply('image-reward:'.$job,$user,2,'image_reward'); }
    public static function rewardConversion(string $user,string $job): void { self::apply('conversion-reward:'.$job,$user,5,'conversion_reward'); }
    public static function grant(array $admin,string $id,array $body): array {
        if (($admin['role'] ?? '')!=='admin') json_error('Administrator access required.',403);
        if (!Auth::findById($id)) json_error('User not found.',404);
        $amount=$body['amount'] ?? null; $request=(string)($body['request_id'] ?? '');
        if (!is_int($amount) || $amount<1 || $amount>1000000) throw new DomainException('Add between 1 and 1,000,000 whole points.');
        if (!preg_match('/^[a-f0-9]{32}$/D',$request)) throw new DomainException('A valid points request ID is required.');
        self::apply('admin-grant:'.$admin['id'].':'.$request,$id,$amount,'admin_grant',(string)$admin['id'],mb_substr(trim((string)($body['note'] ?? '')),0,200));
        return ['ok'=>true,'points'=>self::summary($id)];
    }
}
