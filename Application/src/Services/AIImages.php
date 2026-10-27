<?php
declare(strict_types=1);

/** AI → Images job queue and optional contributor workers. */
final class AIImages
{
    private const JOBS = 'ai-image-jobs';
    private const WORKERS = 'ai-image-workers';
    private const PAIRS = 'ai-image-pairs';
    private const ONLINE_SECONDS = 45;
    private static function q(string $sql, array $p = []): PDOStatement { return Arcade::query($sql, $p); }

    /** Serialize read/modify/write operations with a row lock on the existing documents table. */
    private static function mutate(string $name, callable $fn)
    {
        return \Vaultcast\Database::transaction(static function (PDO $pdo) use ($name, $fn) {
            $insert = $pdo->prepare('INSERT IGNORE INTO vaultcast_documents(name,payload) VALUES (?,?)');
            $insert->execute([$name, '{}']);
            $read = $pdo->prepare('SELECT payload FROM vaultcast_documents WHERE name=? FOR UPDATE');
            $read->execute([$name]);
            $value = json_decode((string)$read->fetchColumn(), true);
            if (!is_array($value)) $value = [];
            $result = $fn($value);
            \Vaultcast\Repository::putDocument($name, $value);
            return $result;
        });
    }
    private static function read(string $name): array {
        $value = \Vaultcast\Repository::document($name, []);
        return is_array($value) ? $value : [];
    }
    private static function id(): string { return bin2hex(random_bytes(8)); }
    private static function signed(array $user): void { Arcade::signed($user); }

    public static function state(array $user): array {
        self::signed($user);
        $jobs = array_values(array_filter(self::read(self::JOBS), static function ($j) use ($user) { return (string)($j['user_id'] ?? '') === (string)$user['id']; }));
        usort($jobs, static function ($a, $b) { return (int)($b['created_at'] ?? 0) <=> (int)($a['created_at'] ?? 0); });
        $queue = array_values(array_filter(self::read(self::JOBS), static function ($j) { return in_array($j['status'] ?? '', ['queued', 'running'], true); }));
        usort($queue, static function ($a, $b) { return (int)($a['created_at'] ?? 0) <=> (int)($b['created_at'] ?? 0); });
        $positions = [];
        $position = 0;
        foreach ($queue as $job) { if (($job['status'] ?? '') === 'queued') $positions[(string)$job['id']] = ++$position; }
        foreach ($jobs as &$job) {
            $job['queue_position'] = $positions[(string)$job['id']] ?? null;
            $job['image_url'] = !empty($job['picture_id']) ? '/api/misc/pictures/' . rawurlencode((string)$job['picture_id']) : null;
            unset($job['user_id'], $job['worker_id']);
        }
        unset($job);
        $workers = self::workersOnline();
        return ['ok' => true, 'jobs' => array_slice($jobs, 0, 100), 'queue' => ['waiting' => $position, 'running' => count($queue) - $position], 'workers' => $workers];
    }
    private static function workersOnline(): array {
        $all = self::read(self::WORKERS); $online = [];
        foreach ($all as $worker) if (!empty($worker['ready']) && (int)($worker['last_seen'] ?? 0) >= time() - self::ONLINE_SECONDS) $online[] = ['id' => $worker['id'], 'name' => $worker['name'] ?? 'Contributor PC', 'model' => $worker['model_name'] ?? '', 'last_seen' => (int)$worker['last_seen']];
        return ['online' => count($online), 'connected' => count(array_filter($all, static function ($w) { return (int)($w['last_seen'] ?? 0) >= time() - self::ONLINE_SECONDS; })), 'total' => count($all), 'list' => $online];
    }

    public static function createJob(array $user, array $body): array {
        self::signed($user);
        $prompt = trim((string)($body['prompt'] ?? ''));
        if ($prompt === '' || mb_strlen($prompt) > 1200) throw new DomainException('Enter a prompt of up to 1,200 characters.');
        $negative = trim((string)($body['negative_prompt'] ?? ''));
        if (mb_strlen($negative) > 600) throw new DomainException('The negative prompt must be 600 characters or fewer.');
        $width = filter_var($body['width'] ?? 1024, FILTER_VALIDATE_INT); $height = filter_var($body['height'] ?? 1024, FILTER_VALIDATE_INT);
        if ($width === false || $height === false || $width < 512 || $height < 512 || $width > 1536 || $height > 1536 || $width % 8 !== 0 || $height % 8 !== 0 || $width * $height > 1048576) throw new DomainException('Use dimensions from 512 to 1536 pixels, in multiples of 8, with no more than 1 megapixel total.');
        $steps = filter_var($body['steps'] ?? 30, FILTER_VALIDATE_INT);
        if ($steps === false || $steps < 1 || $steps > 100) throw new DomainException('Choose between 1 and 100 generation steps.');
        $count = filter_var($body['count'] ?? 1, FILTER_VALIDATE_INT);
        if ($count === false || $count < 1 || $count > 3) throw new DomainException('Choose between 1 and 3 images per request.');
        $cfgScale = filter_var($body['cfg_scale'] ?? 7, FILTER_VALIDATE_FLOAT);
        if ($cfgScale === false || !is_finite((float)$cfgScale) || $cfgScale < 0 || $cfgScale > 30) throw new DomainException('CFG scale must be between 0 and 30.');
        $hiT1 = filter_var($body['hidiffusion_t1_ratio'] ?? 0.4, FILTER_VALIDATE_FLOAT);
        $hiT2 = filter_var($body['hidiffusion_t2_ratio'] ?? 0, FILTER_VALIDATE_FLOAT);
        if ($hiT1 === false || !is_finite((float)$hiT1) || $hiT1 < 0 || $hiT1 > 1 || $hiT2 === false || !is_finite((float)$hiT2) || $hiT2 < 0 || $hiT2 > 1) throw new DomainException('HiDiffusion ratios must be between 0 and 1.');
        $schedulers = ['ddim','ddpm','deis','deis_k','lms','lms_k','pndm','heun','heun_k','euler','euler_k','euler_a','kdpm_2','kdpm_2_k','kdpm_2_a','kdpm_2_a_k','dpmpp_2s','dpmpp_2s_k','dpmpp_2m','dpmpp_2m_k','dpmpp_2m_sde','dpmpp_2m_sde_k','dpmpp_3m','dpmpp_3m_k','dpmpp_sde','dpmpp_sde_k','er_sde','unipc','unipc_k','lcm','tcd'];
        $scheduler = (string)($body['scheduler'] ?? 'euler_a');
        if (!in_array($scheduler, $schedulers, true)) throw new DomainException('Choose a scheduler supported by InvokeAI.');
        $seed = null;
        if (array_key_exists('seed', $body) && $body['seed'] !== null && $body['seed'] !== '') {
            $seed = filter_var($body['seed'], FILTER_VALIDATE_INT);
            if ($seed === false || $seed < 0 || $seed > 4294967295) throw new DomainException('Seed must be a whole number from 0 to 4,294,967,295, or leave it blank for a random seed.');
        }
        $boolean = static function (string $key, bool $default = false) use ($body): bool { return filter_var($body[$key] ?? $default, FILTER_VALIDATE_BOOLEAN); };
        $settings = ['steps'=>$steps, 'cfg_scale'=>(float)$cfgScale, 'scheduler'=>$scheduler, 'seed'=>$seed,
            'hidiffusion'=>$boolean('hidiffusion'), 'hidiffusion_raunet'=>$boolean('hidiffusion_raunet', true), 'hidiffusion_window_attn'=>$boolean('hidiffusion_window_attn', true),
            'hidiffusion_t1_ratio'=>(float)$hiT1, 'hidiffusion_t2_ratio'=>(float)$hiT2, 'fp32'=>$boolean('fp32'), 'tiled'=>$boolean('tiled'),
            'seamless_x'=>$boolean('seamless_x'), 'seamless_y'=>$boolean('seamless_y')];
        $now = time(); $ids = [];
        for ($i = 0; $i < $count; $i++) $ids[] = self::id();
        self::mutate(self::JOBS, static function (&$jobs) use ($user, $prompt, $negative, $width, $height, $settings, $now, $ids, $count) {
            $ownQueued = 0;
            foreach ($jobs as $job) if ((string)($job['user_id'] ?? '') === (string)$user['id'] && in_array($job['status'] ?? '', ['queued', 'running'], true)) $ownQueued++;
            if ($ownQueued + $count > 3) throw new DomainException('You can have up to three image requests queued or generating at a time.');
            foreach ($ids as $i => $id) {
                $jobSettings = $settings;
                if ($jobSettings['seed'] !== null) $jobSettings['seed'] = ($jobSettings['seed'] + $i) % 4294967296;
                $jobs[] = ['id' => $id, 'user_id' => (string)$user['id'], 'prompt' => $prompt, 'negative_prompt' => $negative, 'width' => $width, 'height' => $height] + $jobSettings + [
                    'status' => 'queued', 'progress' => 0, 'created_at' => $now + $i, 'updated_at' => $now, 'picture_id' => null, 'error' => ''];
            }
            if (count($jobs) > 2000) $jobs = array_values(array_filter($jobs, static function ($j) { return in_array($j['status'] ?? '', ['queued', 'running'], true) || (int)($j['created_at'] ?? 0) > time() - 86400 * 7; }));
        });
        return ['ok' => true, 'job_ids' => $ids];
    }
    public static function cancel(array $user, string $id): array {
        self::signed($user);
        $found = self::mutate(self::JOBS, static function (&$jobs) use ($user, $id) {
            foreach ($jobs as &$job) if (($job['id'] ?? '') === $id && (string)($job['user_id'] ?? '') === (string)$user['id']) {
                if (($job['status'] ?? '') === 'running') throw new DomainException('This image has already started generating.');
                if (($job['status'] ?? '') !== 'queued') throw new DomainException('This request can no longer be canceled.');
                $job['status'] = 'canceled'; $job['updated_at'] = time(); return true;
            }
            return false;
        });
        if (!$found) throw new DomainException('Image request not found.');
        return ['ok' => true];
    }

    public static function pair(array $user): array {
        self::signed($user);
        $code = bin2hex(random_bytes(16)); $hash = hash('sha256', $code); $expires = time() + 600;
        self::mutate(self::PAIRS, static function (&$pairs) use ($user, $hash, $expires) {
            foreach ($pairs as $key => $pair) if ((int)($pair['expires_at'] ?? 0) < time()) unset($pairs[$key]);
            $pairs[$hash] = ['user_id' => (string)$user['id'], 'expires_at' => $expires];
        });
        return ['ok' => true, 'pair_code' => $code, 'expires_at' => $expires];
    }
    public static function registerWorker(array $body): array {
        $code = strtolower(trim((string)($body['pair_code'] ?? '')));
        if (!preg_match('/^[a-f0-9]{32}$/D', $code)) throw new DomainException('Enter the pairing code shown in Vaultcast.');
        $codeHash = hash('sha256', $code); $token = bin2hex(random_bytes(32)); $tokenHash = hash('sha256', $token); $id = self::id();
        $name = mb_substr(trim((string)($body['name'] ?? 'Contributor PC')), 0, 60) ?: 'Contributor PC';
        $modelName = mb_substr(trim((string)($body['model_name'] ?? '')), 0, 100);
        $modelBase = trim((string)($body['model_base'] ?? ''));
        if ($modelName === '' || !in_array($modelBase, ['sd-1', 'sd-2', 'sdxl'], true)) throw new DomainException('This InvokeAI model family is not supported by the current Vaultcast worker.');
        $worker = self::mutate(self::PAIRS, static function (&$pairs) use ($codeHash, $tokenHash, $id, $name, $modelName, $modelBase) {
            $pair = $pairs[$codeHash] ?? null;
            if (!is_array($pair) || (int)$pair['expires_at'] < time()) throw new DomainException('Pairing code expired or was already used. Generate a new one in Vaultcast.');
            unset($pairs[$codeHash]);
            return ['id' => $id, 'name' => $name, 'user_id' => $pair['user_id'], 'token_hash' => $tokenHash, 'model_name' => $modelName, 'model_base' => $modelBase, 'ready' => true, 'last_seen' => time(), 'created_at' => time()];
        });
        self::mutate(self::WORKERS, static function (&$workers) use ($worker) { $workers[$worker['id']] = $worker; });
        return ['ok' => true, 'worker_id' => $id, 'worker_token' => $token, 'server_time' => time()];
    }
    private static function authenticateWorker(): array {
        $token = (string)($_SERVER['HTTP_X_VAULTCAST_WORKER'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) json_error('Worker credential required.', 401);
        $hash = hash('sha256', $token);
        foreach (self::read(self::WORKERS) as $worker) if (hash_equals((string)($worker['token_hash'] ?? ''), $hash)) return $worker;
        json_error('Worker credential is invalid.', 401);
    }
    private static function touchWorker(array $worker, array $status = []): void {
        self::mutate(self::WORKERS, static function (&$workers) use ($worker, $status) { if (isset($workers[$worker['id']])) {
            $workers[$worker['id']]['last_seen'] = time();
            if (isset($status['ready'])) $workers[$worker['id']]['ready'] = (bool)$status['ready'];
            if (isset($status['model_name'])) $workers[$worker['id']]['model_name'] = mb_substr(trim((string)$status['model_name']), 0, 100);
            if (isset($status['model_base']) && in_array($status['model_base'], ['sd-1','sd-2','sdxl'], true)) $workers[$worker['id']]['model_base'] = $status['model_base'];
        } });
    }
    public static function worker(string $action, string $id = ''): void {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($action === 'register' && $method === 'POST') json_out(self::registerWorker(body_json()));
        $worker = self::authenticateWorker();
        if ($action === 'heartbeat' && $method === 'POST') { self::touchWorker($worker, body_json()); json_out(['ok' => true, 'worker_id' => $worker['id']]); }
        self::touchWorker($worker);
        if ($action === 'claim' && $method === 'POST') {
            $job = self::mutate(self::JOBS, static function (&$jobs) use ($worker) {
                $now = time();
                foreach ($jobs as &$stale) if (($stale['status'] ?? '') === 'running' && (int)($stale['updated_at'] ?? 0) < $now - 180) { $stale['status'] = 'queued'; $stale['worker_id'] = null; $stale['progress'] = 0; }
                unset($stale);
                usort($jobs, static function ($a, $b) { return (int)($a['created_at'] ?? 0) <=> (int)($b['created_at'] ?? 0); });
                foreach ($jobs as &$candidate) if (($candidate['status'] ?? '') === 'queued') {
                    $candidate['status'] = 'running'; $candidate['worker_id'] = $worker['id']; $candidate['started_at'] = $now; $candidate['updated_at'] = $now; $candidate['progress'] = 1; return $candidate;
                }
                return null;
            });
            json_out(['ok' => true, 'job' => $job ? array_intersect_key($job, array_flip(['id','prompt','negative_prompt','width','height','steps','cfg_scale','scheduler','seed','hidiffusion','hidiffusion_raunet','hidiffusion_window_attn','hidiffusion_t1_ratio','hidiffusion_t2_ratio','fp32','tiled','seamless_x','seamless_y'])) : null]);
        }
        if ($action === 'progress' && $method === 'POST') {
            $pct = max(1, min(99, (int)(body_json()['progress'] ?? 1)));
            $ok = self::mutate(self::JOBS, static function (&$jobs) use ($worker, $id, $pct) {
                foreach ($jobs as &$job) if (($job['id'] ?? '') === $id && ($job['status'] ?? '') === 'running' && ($job['worker_id'] ?? '') === $worker['id']) { $job['progress'] = $pct; $job['updated_at'] = time(); return true; }
                return false;
            });
            if (!$ok) json_error('This job is no longer assigned to this worker.', 409);
            json_out(['ok' => true]);
        }
        if ($action === 'complete' && $method === 'POST') {
            $found = null;
            foreach (self::read(self::JOBS) as $job) if (($job['id'] ?? '') === $id && ($job['status'] ?? '') === 'running' && ($job['worker_id'] ?? '') === $worker['id']) { $found = $job; break; }
            if (!$found) json_error('This job is no longer assigned to this worker.', 409);
            if (empty($_FILES['file']['tmp_name'])) json_error('Upload the generated image as file.', 400);
            $owner = Auth::findById((string)$found['user_id']);
            if (!$owner) json_error('The image owner no longer exists.', 404);
            $picture = Pictures::saveGenerated($owner, (string)$_FILES['file']['tmp_name'], (string)($_FILES['file']['name'] ?? 'generated.png'));
            self::mutate(self::JOBS, static function (&$jobs) use ($worker, $id, $picture) {
                foreach ($jobs as &$job) if (($job['id'] ?? '') === $id && ($job['status'] ?? '') === 'running' && ($job['worker_id'] ?? '') === $worker['id']) { $job['status'] = 'completed'; $job['picture_id'] = $picture['id']; $job['progress'] = 100; $job['updated_at'] = time(); $job['finished_at'] = time(); return; }
            });
            json_out(['ok' => true, 'picture_id' => $picture['id']]);
        }
        if ($action === 'fail' && $method === 'POST') {
            $msg = mb_substr(trim((string)(body_json()['error'] ?? 'Generation failed.')), 0, 300);
            self::mutate(self::JOBS, static function (&$jobs) use ($worker, $id, $msg) {
                foreach ($jobs as &$job) if (($job['id'] ?? '') === $id && ($job['status'] ?? '') === 'running' && ($job['worker_id'] ?? '') === $worker['id']) { $job['status'] = 'failed'; $job['error'] = $msg; $job['updated_at'] = time(); $job['finished_at'] = time(); return; }
            });
            json_out(['ok' => true]);
        }
        json_error('AI worker endpoint not found.', 404);
    }
}
