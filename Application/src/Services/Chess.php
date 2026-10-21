<?php
declare(strict_types=1);

/**
 * Compact chess rules used to validate async games between users (Play → Arcade → Chess).
 * Squares are 0..63 (a1 = 0, h8 = 63). The board is a 64-character string: PNBRQK white,
 * pnbrqk black, '.' empty. Moves use UCI notation ("e2e4", "e7e8q").
 */
final class Chess {
    const START = 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1';
    private const KNIGHT = [[1,2],[2,1],[2,-1],[1,-2],[-1,-2],[-2,-1],[-2,1],[-1,2]];
    private const KING = [[1,0],[1,1],[0,1],[-1,1],[-1,0],[-1,-1],[0,-1],[1,-1]];
    private const ROOK = [[1,0],[-1,0],[0,1],[0,-1]];
    private const BISHOP = [[1,1],[1,-1],[-1,1],[-1,-1]];

    public static function parse(string $fen): array {
        $parts = preg_split('/\s+/', trim($fen));
        if (count($parts) < 4) throw new DomainException('Invalid position.');
        $rows = explode('/', $parts[0]);
        if (count($rows) !== 8) throw new DomainException('Invalid position.');
        $board = array_fill(0, 64, '.');
        foreach ($rows as $i => $row) {
            $rank = 7 - $i; $file = 0;
            foreach (str_split($row) as $ch) {
                if (ctype_digit($ch)) { $file += (int)$ch; continue; }
                if (strpos('pnbrqkPNBRQK', $ch) === false || $file > 7) throw new DomainException('Invalid position.');
                $board[$rank * 8 + $file] = $ch; $file++;
            }
            if ($file !== 8) throw new DomainException('Invalid position.');
        }
        return ['board' => implode('', $board), 'turn' => $parts[1] === 'b' ? 'b' : 'w', 'castle' => $parts[2] === '-' ? '' : $parts[2],
            'ep' => $parts[3] === '-' ? -1 : self::square($parts[3]), 'half' => (int)($parts[4] ?? 0), 'full' => max(1, (int)($parts[5] ?? 1))];
    }

    public static function fen(array $s): string {
        $rows = [];
        for ($rank = 7; $rank >= 0; $rank--) {
            $row = ''; $empty = 0;
            for ($file = 0; $file < 8; $file++) {
                $p = $s['board'][$rank * 8 + $file];
                if ($p === '.') { $empty++; continue; }
                if ($empty) { $row .= $empty; $empty = 0; }
                $row .= $p;
            }
            $rows[] = $row . ($empty ? $empty : '');
        }
        return implode('/', $rows) . ' ' . $s['turn'] . ' ' . ($s['castle'] !== '' ? $s['castle'] : '-') . ' ' . ($s['ep'] >= 0 ? self::name($s['ep']) : '-') . ' ' . $s['half'] . ' ' . $s['full'];
    }

    public static function square(string $n): int {
        if (!preg_match('/^[a-h][1-8]$/', $n)) return -1;
        return (ord($n[1]) - 49) * 8 + (ord($n[0]) - 97);
    }
    public static function name(int $sq): string { return chr(97 + $sq % 8) . chr(49 + intdiv($sq, 8)); }
    private static function colour(string $p): string { return $p === '.' ? '' : (ctype_upper($p) ? 'w' : 'b'); }

    /** Is square $sq attacked by side $by? */
    public static function attacked(string $b, int $sq, string $by): bool {
        $f = $sq % 8; $r = intdiv($sq, 8);
        $pawnDir = $by === 'w' ? -1 : 1; $pawn = $by === 'w' ? 'P' : 'p';
        foreach ([-1, 1] as $df) { $ff = $f + $df; $rr = $r + $pawnDir; if ($ff >= 0 && $ff < 8 && $rr >= 0 && $rr < 8 && $b[$rr * 8 + $ff] === $pawn) return true; }
        $n = $by === 'w' ? 'N' : 'n'; $k = $by === 'w' ? 'K' : 'k';
        foreach (self::KNIGHT as $d) { $ff = $f + $d[0]; $rr = $r + $d[1]; if ($ff >= 0 && $ff < 8 && $rr >= 0 && $rr < 8 && $b[$rr * 8 + $ff] === $n) return true; }
        foreach (self::KING as $d) { $ff = $f + $d[0]; $rr = $r + $d[1]; if ($ff >= 0 && $ff < 8 && $rr >= 0 && $rr < 8 && $b[$rr * 8 + $ff] === $k) return true; }
        $rq = $by === 'w' ? 'RQ' : 'rq'; $bq = $by === 'w' ? 'BQ' : 'bq';
        foreach ([[self::ROOK, $rq], [self::BISHOP, $bq]] as $set) {
            foreach ($set[0] as $d) {
                $ff = $f + $d[0]; $rr = $r + $d[1];
                while ($ff >= 0 && $ff < 8 && $rr >= 0 && $rr < 8) {
                    $p = $b[$rr * 8 + $ff];
                    if ($p !== '.') { if (strpos($set[1], $p) !== false) return true; break; }
                    $ff += $d[0]; $rr += $d[1];
                }
            }
        }
        return false;
    }

    public static function inCheck(array $s, string $side): bool {
        $k = strpos($s['board'], $side === 'w' ? 'K' : 'k');
        return $k === false ? true : self::attacked($s['board'], $k, $side === 'w' ? 'b' : 'w');
    }

    private static function pseudo(array $s): array {
        $b = $s['board']; $me = $s['turn']; $them = $me === 'w' ? 'b' : 'w'; $out = [];
        for ($sq = 0; $sq < 64; $sq++) {
            $p = $b[$sq];
            if (self::colour($p) !== $me) continue;
            $f = $sq % 8; $r = intdiv($sq, 8); $t = strtolower($p);
            if ($t === 'p') {
                $dir = $me === 'w' ? 1 : -1; $start = $me === 'w' ? 1 : 6; $last = $me === 'w' ? 7 : 0;
                $to = $sq + 8 * $dir;
                if ($to >= 0 && $to < 64 && $b[$to] === '.') {
                    self::pawnMove($out, $sq, $to, intdiv($to, 8) === $last);
                    $two = $sq + 16 * $dir;
                    if ($r === $start && $b[$two] === '.') $out[] = [$sq, $two, ''];
                }
                foreach ([-1, 1] as $df) {
                    $ff = $f + $df; if ($ff < 0 || $ff > 7) continue;
                    $to = ($r + $dir) * 8 + $ff; if ($to < 0 || $to > 63) continue;
                    if (self::colour($b[$to]) === $them || $to === $s['ep']) self::pawnMove($out, $sq, $to, intdiv($to, 8) === $last);
                }
                continue;
            }
            $dirs = $t === 'n' ? self::KNIGHT : ($t === 'k' ? self::KING : ($t === 'r' ? self::ROOK : ($t === 'b' ? self::BISHOP : array_merge(self::ROOK, self::BISHOP))));
            $slide = in_array($t, ['r', 'b', 'q'], true);
            foreach ($dirs as $d) {
                $ff = $f + $d[0]; $rr = $r + $d[1];
                while ($ff >= 0 && $ff < 8 && $rr >= 0 && $rr < 8) {
                    $to = $rr * 8 + $ff; $c = self::colour($b[$to]);
                    if ($c === $me) break;
                    $out[] = [$sq, $to, ''];
                    if ($c !== '' || !$slide) break;
                    $ff += $d[0]; $rr += $d[1];
                }
            }
            if ($t === 'k') {
                $home = $me === 'w' ? 4 : 60;
                if ($sq === $home && !self::attacked($b, $sq, $them)) {
                    $kc = $me === 'w' ? 'K' : 'k'; $qc = $me === 'w' ? 'Q' : 'q';
                    if (strpos($s['castle'], $kc) !== false && $b[$sq + 1] === '.' && $b[$sq + 2] === '.' && $b[$sq + 3] === ($me === 'w' ? 'R' : 'r')
                        && !self::attacked($b, $sq + 1, $them) && !self::attacked($b, $sq + 2, $them)) $out[] = [$sq, $sq + 2, ''];
                    if (strpos($s['castle'], $qc) !== false && $b[$sq - 1] === '.' && $b[$sq - 2] === '.' && $b[$sq - 3] === '.' && $b[$sq - 4] === ($me === 'w' ? 'R' : 'r')
                        && !self::attacked($b, $sq - 1, $them) && !self::attacked($b, $sq - 2, $them)) $out[] = [$sq, $sq - 2, ''];
                }
            }
        }
        return $out;
    }
    private static function pawnMove(array &$out, int $from, int $to, bool $promo): void {
        if (!$promo) { $out[] = [$from, $to, '']; return; }
        foreach (['q', 'r', 'b', 'n'] as $p) $out[] = [$from, $to, $p];
    }

    /** Apply a move without legality checks. */
    public static function apply(array $s, array $m): array {
        $b = $s['board']; $from = $m[0]; $to = $m[1]; $p = $b[$from]; $t = strtolower($p); $me = $s['turn'];
        $capture = $b[$to] !== '.';
        if ($t === 'p' && $to === $s['ep'] && !$capture) { $b[$to + ($me === 'w' ? -8 : 8)] = '.'; $capture = true; }
        $b[$to] = $m[2] !== '' ? ($me === 'w' ? strtoupper($m[2]) : $m[2]) : $p; $b[$from] = '.';
        if ($t === 'k' && abs($to - $from) === 2) {
            if ($to > $from) { $b[$from + 1] = $b[$from + 3]; $b[$from + 3] = '.'; } else { $b[$from - 1] = $b[$from - 4]; $b[$from - 4] = '.'; }
        }
        $castle = $s['castle'];
        foreach ([[4, 'KQ'], [60, 'kq'], [0, 'Q'], [7, 'K'], [56, 'q'], [63, 'k']] as $rule) {
            if ($from === $rule[0] || $to === $rule[0]) $castle = str_replace(str_split($rule[1]), '', $castle);
        }
        return ['board' => $b, 'turn' => $me === 'w' ? 'b' : 'w', 'castle' => $castle,
            'ep' => $t === 'p' && abs($to - $from) === 16 ? intdiv($from + $to, 2) : -1,
            'half' => ($t === 'p' || $capture) ? 0 : $s['half'] + 1, 'full' => $s['full'] + ($me === 'b' ? 1 : 0)];
    }

    public static function legal(array $s): array {
        $out = [];
        foreach (self::pseudo($s) as $m) {
            $n = self::apply($s, $m);
            if (!self::inCheck($n, $s['turn'])) $out[] = self::name($m[0]) . self::name($m[1]) . $m[2];
        }
        return $out;
    }

    /** Validate and play a UCI move; returns the new state. */
    public static function play(array $s, string $uci): array {
        $uci = strtolower(trim($uci));
        if (!preg_match('/^([a-h][1-8])([a-h][1-8])([qrbn]?)$/', $uci, $m)) throw new DomainException('Invalid move.');
        $legal = self::legal($s);
        // A promotion sent without a piece promotes to a queen.
        if ($m[3] === '' && !in_array($uci, $legal, true) && in_array($uci . 'q', $legal, true)) { $uci .= 'q'; $m[3] = 'q'; }
        if (!in_array($uci, $legal, true)) throw new DomainException('That move is not legal.');
        return self::apply($s, [self::square($m[1]), self::square($m[2]), $m[3]]);
    }

    /** 'checkmate' | 'stalemate' | 'fifty' | 'material' | '' (game continues). */
    public static function outcome(array $s): string {
        if (!self::legal($s)) return self::inCheck($s, $s['turn']) ? 'checkmate' : 'stalemate';
        if ($s['half'] >= 100) return 'fifty';
        $rest = preg_replace('/[kK.]/', '', $s['board']);
        if ($rest === '' || $rest === 'n' || $rest === 'N' || $rest === 'b' || $rest === 'B') return 'material';
        return '';
    }
}
