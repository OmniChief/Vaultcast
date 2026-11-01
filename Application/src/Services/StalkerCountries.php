<?php
declare(strict_types=1);

/** Private, bounded live catalogue shared by country browsing and playback. */
final class StalkerCountries {
    const CODES = 'AF AX AL DZ AS AD AO AI AQ AG AR AM AW AU AT AZ BS BH BD BB BY BE BZ BJ BM BT BO BQ BA BW BV BR IO BN BG BF BI CV KH CM CA KY CF TD CL CN CX CC CO KM CK CR HR CU CW CY CZ CI CD DK DJ DM DO EC EG SV GQ ER EE SZ ET FK FO FM FJ FI FR GF PF TF GA GM GE DE GH GI GR GL GD GP GU GT GG GN GW GY HT HM VA HN HK HU IS IN ID IR IQ IE IM IL IT JM JP JE JO KZ KE KI KW KG LA LV LB LS LR LY LI LT LU MO MG MW MY MV ML MT MH MQ MR MU YT MX MD MC MN ME MS MA MZ MM NA NR NP NL NC NZ NI NE NG NU NF KP MK MP NO OM PK PW PA PG PY PE PH PN PL PT PR QA CG RO RU RW RE BL SH KN LC MF PM VC WS SM ST SA SN RS SC SL SG SX SK SI SB SO ZA GS KR SS ES LK PS SD SR SJ SE CH SY TW TJ TZ TH TL TG TK TO TT TN TM TC TV TR UG UA AE GB UM US UY UZ VU VE VN VG VI WF EH YE ZM ZW';
    const GROUPS = ['Entertainment','News','Documentary','Sports','Movies','Kids','Music','Lifestyle','Education','Shopping','Religious','Adult','Other'];

    public static function entry(array $feed,string $id): ?array {return StalkerVod::entry($feed,$id,'live');}
    public static function country(string $text): ?string {
        $aliases=['UK'=>'GB','USA'=>'US','UAE'=>'AE','GER'=>'DE','DEU'=>'DE','FRA'=>'FR','JPN'=>'JP','CAN'=>'CA','AUS'=>'AU'];
        // Bracketed markers can occur anywhere; bare codes must be a prefix.
        preg_match_all('/\[\s*([A-Z]{2,3})\s*\]|\(\s*([A-Z]{2,3})\s*\)|^\s*([A-Z]{2,3})\s*[|:._-]/i',$text,$matches,PREG_SET_ORDER);
        foreach($matches as $match){$code=strtoupper(($match[1]??'')?:($match[2]??'')?:($match[3]??''));$code=$aliases[$code]??$code;if(strpos(' '.self::CODES.' ',' '.$code.' ')!==false)return $code;}
        return null;
    }
    public static function group(string $name,string $genre): string {
        $rules=[
            'Adult'=>'adult|xxx|porn', 'News'=>'news|bbc parliament|cnn|al jazeera|euronews',
            'Sports'=>'sports?|espn|racing|football|cricket|golf|tennis',
            'Documentary'=>'documentar(?:y|ies)|discovery|national geographic|nat geo|history|animal planet|wildlife',
            'Kids'=>'kids?|children|cartoon|nickelodeon|cbeebies|cbbc|disney junior',
            'Movies'=>'movies?|cinema|films?', 'Music'=>'music|mtv|vevo',
            'Shopping'=>'shopping|qvc|teleshopping', 'Religious'=>'religio(?:n|us)|faith|christian|islam|church|worship',
            'Education'=>'education(?:al)?|learning', 'Lifestyle'=>'lifestyle|cooking|food|travel|home|garden',
            'Entertainment'=>'entertainment|general|drama|comedy|series|variety'];
        foreach([$name,$genre] as $text)foreach($rules as $group=>$pattern)if(preg_match('/\b(?:'.$pattern.')\b/iu',$text))return $group;
        return 'Other';
    }
    public static function browse(array $state,array $opts): array {return StalkerVod::liveBrowse($state,$opts);}
}
