"""
SKINSYNTAXVN — INGREDIENT PARSER V2
Deterministic Structural Parsing for Cosmetic Ingredient Strings.
Audit & Research Track — Step 7C.1.

Design Constraints:
1. Deterministic execution: identical raw input -> identical parsed output.
2. Structural parsing: no external medical/dermatological inference.
3. Preserves raw text context for full traceability.
4. Distinguishes INCI compound slashes from delimiter slashes.
5. Resolves numeric commas in chemical nomenclature (e.g., 1,2-hexanediol).
6. Handles commercial blend separators '(and)' without merging distinct chemical entities.
7. Flags ambiguous or malformed tokens with UNCERTAIN rather than silently discarding.
"""

import re
from typing import List, Dict, Any, Tuple

# Established INCI compound patterns where '/' connects co-monomers, fatty acid mixtures, or ester fractions
KNOWN_COMPOUND_SLASH_PATTERNS = [
    r'caprylic/capric',
    r'acrylates/c10[-–]30',
    r'dimethicone/vinyl',
    r'peg/ppg',
    r'acrylate/sodium',
    r'acryloyldimethyltaurate/vp',
    r'phytosteryl/octyldodecyl',
    r'dimethicone/methicone',
    r'methacrylate/sodium',
    r'dimethylacrylamide/sodium',
    r'caprylate/caprate',
    r'polyacrylate-13/polyisobutene',
    r'flower/leaf/stem',
    r'leaf/stem',
    r'root/stem',
    r'fruit/leaf',
    r'bark/leaf',
    r'c14[-–]22/c12[-–]20',
    r'c10[-–]30',
    r'hydroxyethyl/sodium',
    r'butyl/methyl',
    r'bis[-–]peg',
    r'lauryl/myristyl'
]

# Common cosmetic alias pairs where '/' indicates multilingual or regional synonym notation
ALIAS_SLASH_PATTERNS = [
    (r'\baqua\s*/\s*water\s*/\s*eau\b', 'water'),
    (r'\baqua\s*/\s*water\b', 'water'),
    (r'\bwater\s*/\s*aqua\b', 'water'),
    (r'\bparfum\s*/\s*fragrance\b', 'fragrance'),
    (r'\bfragrance\s*/\s*parfum\b', 'fragrance'),
    (r'\beau\s*/\s*water\b', 'water')
]

# Stop phrases that do not represent chemical entities
STOP_PHRASES = {
    'đang cập nhật', 'thành phần', 'ingredients', 'water', 'aqua', 'thanh phan',
    'none', 'null', 'đang cap nhat'
}

def parse_ingredient_v2_detailed(raw_text: str) -> List[Dict[str, Any]]:
    """
    Parses raw ingredient string into structured tokens with traceability metadata and flags.
    
    Returns:
        List of dicts containing:
            - 'token': normalized token string (words joined by underscore)
            - 'raw_context': raw chunk string from which the token was extracted
            - 'flag': PARSED_STANDARD | PRESERVED_COMPOUND | RESOLVED_NUMERIC_COMMA | 
                      RESOLVED_AND_BLEND | UNCERTAIN
    """
    if not raw_text or raw_text.strip() in ('None', 'Đang cập nhật', '', 'null'):
        return []

    t = raw_text

    # 1. Clean line breaks interrupting hyphenated chemical names
    t = re.sub(r'[\r\n]+-\s*', '-', t)
    t = re.sub(r'-\s*[\r\n]+', '-', t)

    # 2. Strip cosmetic registration formula codes and batch +/- tags
    t = re.sub(r'fil\.\s*\d+[\.\w]*', '', t, flags=re.IGNORECASE)
    t = re.sub(r'\[\s*\+/-\s*[^\]]+\]', '', t)

    # 3. Resolve '(and)' or ' and ' commercial raw material blends into delimiter commas
    t = re.sub(r'\s*\(\s*and\s*\)\s*', ', ', t, flags=re.IGNORECASE)
    t = re.sub(r'\s+and\s+', ', ', t, flags=re.IGNORECASE)

    # 4. Canonicalize known alias slashes to avoid artificial merged mega-tokens
    for p, repl in ALIAS_SLASH_PATTERNS:
        t = re.sub(p, repl, t, flags=re.IGNORECASE)

    # 5. Protect numeric commas in chemical nomenclature (e.g., 1,2-hexanediol, 1,3-propanediol)
    # and concentration numbers (e.g. 10,000 ppm) from being split by comma delimiter
    t = re.sub(r'(\b\d+),(\d+[\w-]*)', r'\1__NUMCOMMA__\2', t)

    # 6. Slashes surrounded by whitespace are true ingredient delimiters: "A / B" -> "A, B"
    t = re.sub(r'\s+/\s+', ', ', t)

    # 7. Parentheses handling:
    # - Strip concentration markers: (10%), (1,000 ppm), (0.5%)
    t = re.sub(r'\(\s*\d+([.,]\d+)?\s*(%|ppm|ppb|mg|ml)\s*\)', '', t, flags=re.IGNORECASE)
    # - Strip processing/form annotations: (nano), (preservative)
    t = re.sub(r'\(\s*(nano|preservative|chất bảo quản)\s*\)', '', t, flags=re.IGNORECASE)
    # - Replace remaining parentheses with space so adjacent words don't accidentally merge
    t = re.sub(r'[()[\]{}]', ' ', t)

    # 8. Split by delimiters: commas, semicolons, bullets, newlines, pipes
    raw_chunks = re.split(r'[,;•\r\n|]+', t)

    items = []
    seen = set()

    for c in raw_chunks:
        raw_c = c.strip()
        if not raw_c:
            continue
        c_clean = raw_c.lower()

        # Check for numeric comma presence
        had_numeric_comma = '__numcomma__' in c_clean
        c_clean = c_clean.replace('__numcomma__', '_')

        # Strip non-alphanumeric boundaries
        c_clean = re.sub(r'^[^\w/]+|[^\w/]+$', '', c_clean)
        c_clean = re.sub(r'\s+', ' ', c_clean).strip()

        if len(c_clean) < 2 or c_clean.isdigit():
            continue
        if c_clean in STOP_PHRASES:
            continue

        # Determine structural flag
        if '/' in c_clean:
            is_known_compound = any(re.search(p, c_clean) for p in KNOWN_COMPOUND_SLASH_PATTERNS)
            if is_known_compound:
                flag = 'PRESERVED_COMPOUND'
            else:
                flag = 'UNCERTAIN'
        elif had_numeric_comma:
            flag = 'RESOLVED_NUMERIC_COMMA'
        elif re.search(r'[^a-zA-Z\d\s_/–-]', c_clean):
            flag = 'UNCERTAIN'
        elif len(c_clean.split()) > 6:
            # Overly long unstructured chunk, likely missing delimiter in raw crawl
            flag = 'UNCERTAIN'
        else:
            flag = 'PARSED_STANDARD'

        # Join multi-word entity with underscore so it forms a single atomic token
        token = re.sub(r'[\s\-–]+', '_', c_clean).strip('_')

        if len(token) >= 2 and token not in seen:
            seen.add(token)
            items.append({
                'token': token,
                'raw_context': raw_c,
                'flag': flag
            })

    return items


def parse_ingredient_entities_v2(raw_text: str) -> List[str]:
    """
    Convenience wrapper returning just the list of unique parsed token strings.
    Matches the function signature expected by downstream vectorizers.
    """
    detailed = parse_ingredient_v2_detailed(raw_text)
    return [item['token'] for item in detailed]
