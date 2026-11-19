'use strict';

const namesToCountries = {
  'UNITED KINGDOM': 'GB',
  'GREAT BRITAIN': 'GB',
  BRITAIN: 'GB',
  ENGLAND: 'GB',
  SCOTLAND: 'GB',
  WALES: 'GB',
  'UNITED STATES': 'US',
  AMERICAN: 'US',
  CANADA: 'CA',
  AUSTRALIA: 'AU',
  'NEW ZEALAND': 'NZ',
  IRELAND: 'IE',
  FRANCE: 'FR',
  GERMANY: 'DE',
  SPAIN: 'ES',
  ITALY: 'IT',
  PORTUGAL: 'PT',
  NETHERLANDS: 'NL',
  BELGIUM: 'BE',
  SWITZERLAND: 'CH',
  AUSTRIA: 'AT',
  POLAND: 'PL',
  TURKEY: 'TR',
  GREECE: 'GR',
  INDIA: 'IN',
  JAPAN: 'JP',
  BRAZIL: 'BR',
  MEXICO: 'MX',
  ALGERIA: 'DZ',
  BAHRAIN: 'BH',
  ARGENTINA: 'AR',
};
const countryAliases = {
  UK: 'GB',
  EN: 'GB',
  ENG: 'GB',
  USA: 'US',
  NA: 'US',
  UAE: 'AE',
  GER: 'DE',
  DEU: 'DE',
  FRA: 'FR',
  JPN: 'JP',
  CAN: 'CA',
  AUS: 'AU',
  NZL: 'NZ',
  IRL: 'IE',
  NLD: 'NL',
  ESP: 'ES',
  ITA: 'IT',
  POR: 'PT',
  TUR: 'TR',
  IND: 'IN',
  BRA: 'BR',
  POL: 'PL',
};
const countries = new Set(
  'AF AX AL DZ AS AD AO AI AQ AG AR AM AW AU AT AZ BS BH BD BB BY BE BZ BJ BM BT BO BQ BA BW BV BR IO BN BG BF BI CV KH CM CA KY CF TD CL CN CX CC CO KM CK CR HR CU CW CY CZ CI CD DK DJ DM DO EC EG SV GQ ER EE SZ ET FK FO FM FJ FI FR GF PF TF GA GM GE DE GH GI GR GL GD GP GU GT GG GN GW GY HT HM VA HN HK HU IS IN ID IR IQ IE IM IL IT JM JP JE JO KZ KE KI KW KG LA LV LB LS LR LY LI LT LU MO MG MW MY MV ML MT MH MQ MR MU YT MX MD MC MN ME MS MA MZ MM NA NR NP NL NC NZ NI NE NG NU NF KP MK MP NO OM PK PW PA PG PY PE PH PN PL PT PR QA CG RO RU RW RE BL SH KN LC MF PM VC WS SM ST SA SN RS SC SL SG SX SK SI SB SO ZA GS KR SS ES LK PS SD SR SJ SE CH SY TW TJ TZ TH TL TG TK TO TT TN TM TC TV TR UG UA AE GB UM US UY UZ VU VE VN VG VI WF EH YE ZM ZW'.split(
    ' ',
  ),
);
const namePatterns = Object.entries(namesToCountries)
  .filter(([name, code]) => code !== 'GB' && name !== 'UNITED STATES' && name !== 'AMERICAN')
  .map(([name, code]) => [new RegExp('(?<![\\p{L}])' + name + '(?![\\p{L}])', 'iu'), code]);
function country(text) {
  const flag = /[\u{1f1e6}-\u{1f1ff}]{2}/u.exec(text);
  if (flag) {
    const code = Array.from(flag[0])
      .map((char) => String.fromCharCode(65 + char.codePointAt(0) - 0x1f1e6))
      .join('');
    if (countries.has(code)) return code;
  }
  if (/^\s*(?:UNITED KINGDOM|GREAT BRITAIN|BRITAIN|ENGLAND|SCOTLAND|WALES)\b/i.test(text)) return 'GB';
  if (/^\s*(?:UNITED STATES(?: OF AMERICA)?|AMERICAN)\b/i.test(text)) return 'US';
  for (const [pattern, code] of namePatterns) if (pattern.test(text)) return code;
  const clean = text.replace(/[\u200e\u200f\u202a-\u202e\u2066-\u2069\ufeff]/gu, '');
  const matches = [
    ...clean.matchAll(
      /[\[（(]\s*([A-Z]{2,3})\s*[\]）)]|[|┃│¦]\s*([A-Z]{2,3})\s*[|┃│¦]|(?:^|[|┃│¦/])\s*([A-Z]{2,3})(?=\s*(?:[|┃│¦:._\-\s]|$))/giu,
    ),
  ];
  for (const match of matches) {
    let code = (match[1] || match[2] || match[3]).toUpperCase();
    if (code === 'AR') continue;
    code = countryAliases[code] ?? code;
    if (countries.has(code)) return code;
  }
  return 'ZZ';
}
const rules = Object.entries({
  Adult: 'adult|xxx|porn',
  Sports:
    'sports?|espn|racing|football|soccer|cricket|golf|tennis|sky sports|tnt sports|bein|dazn|ufc|boxing|epl|efl|uefa|formula 1|f1 tv|ppv',
  News: 'news|bbc parliament|cnn|al jazeera|euronews|politics',
  Documentary:
    'documentar(?:y|ies)|national geographic|nat geo|history|animal planet|wildlife|discovery channel',
  Kids: 'kids?|children|cartoon|nickelodeon|cbeebies|cbbc|disney junior|baby',
  Movies: 'movies?|cinema|films?|sky cinema|film4|on demand|vod',
  Music: 'music|mtv|vevo|radio|concert',
  Shopping: 'shopping|qvc|teleshopping',
  Religious: 'religio(?:n|us)|faith|christian|islam|church|worship',
  Education: 'education(?:al)?|learning',
  Lifestyle: 'lifestyle|cooking|food|travel|home|garden',
  General: 'general|freeview|terrestrial|bbc one|bbc two|itv1|channel 4|channel 5|24/7',
  Entertainment: 'entertainment|drama|comedy|series|variety|sky max|sky mix',
}).map(([label, pattern]) => [label, new RegExp('\\b(?:' + pattern + ')\\b', 'iu')]);
function genre(name, folder = name) {
  const normal = (folder + ' ' + name).replace(/[^\p{L}\p{N}]+/gu, ' ').toUpperCase();
  if (/(?:^|\s)GENERAL(?:\s|$)/u.test(normal)) return 'General';
  if (/(?:^|\s)ENTERTAINMENT(?:\s|$)/u.test(normal)) return 'Entertainment';
  for (const text of new Set([folder, name])) {
    for (const [label, pattern] of rules) if (pattern.test(text)) return label;
  }
  return 'Other';
}

module.exports = { countries, country, genre };
