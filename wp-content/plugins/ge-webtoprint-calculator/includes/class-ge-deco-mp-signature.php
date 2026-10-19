<?php
/** Payment webhook signature only. Does not trust bodies or mark orders paid. */
final class GE_Deco_MP_Signature {
    public static function verify($header,$request_id,$query_id,$secret,$now) {
        if(!is_string($secret)||strlen($secret)<16||!is_string($header)||strlen($header)>512
            ||!is_string($request_id)||!preg_match('/^[a-zA-Z0-9_-]{1,128}$/D',$request_id)
            ||!is_string($query_id)||!preg_match('/^[1-9][0-9]{0,29}$/D',$query_id)||!is_int($now)) {
            throw new InvalidArgumentException('invalid_mp_signature');
        }
        $parts=array();
        foreach(explode(',',$header) as $entry){$pair=explode('=',trim($entry),2);if(count($pair)!==2||isset($parts[$pair[0]]))throw new InvalidArgumentException('invalid_mp_signature');$parts[$pair[0]]=trim($pair[1]);}
        if(count($parts)!==2||!preg_match('/^[0-9]{10,13}$/D',$parts['ts']??'')||!preg_match('/^[a-f0-9]{64}$/D',$parts['v1']??''))throw new InvalidArgumentException('invalid_mp_signature');
        $timestamp=(int)$parts['ts'];if(strlen($parts['ts'])===13)$timestamp=intdiv($timestamp,1000);
        if(abs($now-$timestamp)>300)throw new InvalidArgumentException('expired_mp_signature');
        $canonical='id:'.$query_id.';request-id:'.$request_id.';ts:'.$parts['ts'].';';
        if(!hash_equals(hash_hmac('sha256',$canonical,$secret),$parts['v1']))throw new InvalidArgumentException('invalid_mp_signature');
        // Deduplicate delivery, not payment ID: later status changes must be reconciled too.
        return hash('sha256',$canonical.':'.$parts['v1']);
    }
}
