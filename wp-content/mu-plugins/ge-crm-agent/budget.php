<?php
/** Private, durable accounting. USD is stored as integer microdollars. */
final class GE_CRM_Agent_Budget {
    const MONTH_LIMIT = 50000000;
    const THREAD_LIMIT = 2000000;
    private $dir;
    public function __construct($dir) { $this->dir = $dir; }
    private function transact($fn) {
        if (!is_dir($this->dir) || !is_writable($this->dir)) throw new RuntimeException('Almacenamiento privado de IA no disponible.');
        $lock = fopen($this->dir . '/budget.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('No se pudo bloquear el presupuesto.');
        try {
            $path = $this->dir . '/budget.json';
            $state = is_file($path) ? json_decode(file_get_contents($path), true) : array('version'=>1,'calls'=>array());
            if (!is_array($state) || ($state['version'] ?? 0) !== 1 || !is_array($state['calls'] ?? null)) throw new RuntimeException('Registro de consumo inválido; IA pausada.');
            $out = $fn($state);
            $tmp = tempnam($this->dir, 'budget-');
            if (!$tmp || file_put_contents($tmp, json_encode($state, JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)) === false || !chmod($tmp, 0600) || !rename($tmp, $path)) throw new RuntimeException('No se pudo confirmar el registro de consumo.');
            return $out;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
    public static function month($now = null) { $zone=new DateTimeZone('America/Argentina/Buenos_Aires'); return (new DateTimeImmutable($now ?: 'now', $zone))->setTimezone($zone)->format('Y-m'); }
    public static function totals($state, $month, $scope, $thread = '') {
        $out = array('charged'=>0,'held'=>0,'calls'=>0);
        foreach ($state['calls'] as $c) {
            if ($c['month'] !== $month || $c['scope'] !== $scope || ($thread !== '' && $c['thread'] !== $thread)) continue;
            $out[$c['status'] === 'done' ? 'charged' : 'held'] += $c['status'] === 'done' ? $c['cost'] : $c['reserve'];
            $out['calls']++;
        }
        $out['committed'] = $out['charged'] + $out['held'];
        $out['warning'] = $out['committed'] >= 40000000;
        $out['limit'] = self::MONTH_LIMIT;
        return $out;
    }
    public function summary($scope) { return $this->transact(function(&$s) use ($scope) { return self::totals($s, self::month(), $scope); }); }
    public function begin($scope, $thread, $fingerprint, $reserve) {
        if (!is_int($reserve) || $reserve < 1 || $reserve > 100000) throw new RuntimeException('Reserva de consumo inválida.');
        return $this->transact(function(&$s) use ($scope,$thread,$fingerprint,$reserve) {
            // Check across months: a repeated request never silently buys the same draft twice.
            foreach ($s['calls'] as $id=>$c) if ($c['scope'] === $scope && $c['fingerprint'] === $fingerprint) {
                if ($c['status'] === 'done' && isset($c['draft'])) return array('cached'=>true,'id'=>$id,'draft'=>$c['draft']);
                throw new RuntimeException('Esta solicitud ya está en curso o requiere revisar su consumo. No se repetirá automáticamente.');
            }
            $month = self::month();
            $total = self::totals($s,$month,$scope);
            $personal = self::totals($s,$month,$scope,$thread);
            if (!empty($s['paused_scopes'][$scope])) throw new RuntimeException('El consumo requiere conciliación. IA pausada.');
            if ($total['committed'] + $reserve > self::MONTH_LIMIT) throw new RuntimeException('Se alcanzó el límite mensual de US$50. IA pausada.');
            if ($personal['committed'] + $reserve > self::THREAD_LIMIT) throw new RuntimeException('Esta conversación alcanzó su límite de US$2 del mes. Requiere revisión humana.');
            $id = bin2hex(random_bytes(16));
            $s['calls'][$id] = array('month'=>$month,'scope'=>$scope,'thread'=>$thread,'fingerprint'=>$fingerprint,'reserve'=>$reserve,'cost'=>0,'status'=>'reserved','created_at'=>gmdate('c'));
            return array('cached'=>false,'id'=>$id);
        });
    }
    public function finish($id, $usage, $draft) {
        $result = $this->transact(function(&$s) use ($id,$usage,$draft) {
            if (!isset($s['calls'][$id]) || $s['calls'][$id]['status'] !== 'reserved') throw new RuntimeException('Reserva no disponible.');
            if (!isset($usage['input_tokens'],$usage['output_tokens']) || !is_int($usage['input_tokens']) || !is_int($usage['output_tokens']) || $usage['input_tokens'] < 0 || $usage['output_tokens'] < 0) throw new RuntimeException('La API no informó consumo válido. Reserva conservada.');
            // Standard GPT-5.4-mini rates; conservatively charge cached input at full rate.
            $cost = (int)ceil($usage['input_tokens'] * 0.75 + $usage['output_tokens'] * 4.50);
            $c =& $s['calls'][$id];
            if ($cost > $c['reserve']) { $s['paused_scopes'][$c['scope']]=true; $draft=null; }
            $c['cost']=$cost; $c['usage']=$usage; $c['status']='done'; $c['draft']=$draft; $c['completed_at']=gmdate('c');
            return array('cost'=>$cost,'exceeded'=>$cost>$c['reserve']);
        });
        if ($result['exceeded']) throw new RuntimeException('El consumo superó la reserva; IA pausada hasta conciliar.');
        return $result['cost'];
    }
    public function uncertain($id) { return $this->transact(function(&$s) use ($id) { if (isset($s['calls'][$id]) && $s['calls'][$id]['status'] === 'reserved') $s['calls'][$id]['status']='uncertain'; }); }
    public function latest($scope,$thread) { return $this->transact(function(&$s) use ($scope,$thread) { foreach (array_reverse($s['calls']) as $c) if ($c['scope']===$scope && $c['thread']===$thread && $c['status']==='done') return $c; return null; }); }
}
