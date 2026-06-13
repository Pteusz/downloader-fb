"""
player_parser.py
────────────────
Parser de jogador do Futbin — suporta /25/ e /26/ com fallbacks em cascata.

Autodetecta a versão pelo prefixo das classes no HTML.
A saída é sempre idêntica, independente da versão da página.

Uso:
    from core.player_parser import extract_cards, parse_full_player

    cards  = extract_cards(listing_html)          # fase 1: listagem
    player = parse_full_player(detail_html)        # fase 2: página do jogador
"""

import re
import logging
from bs4 import BeautifulSoup

log = logging.getLogger(__name__)

BASE_URL = "https://www.futbin.com"


# ════════════════════════════════════════════════════════════
# DETECÇÃO DE VERSÃO
# ════════════════════════════════════════════════════════════

def detect_version(html: str) -> str:
    """Retorna '26' ou '25' conforme o prefixo encontrado no HTML."""
    if "playercard-26" in html:
        return "26"
    if "playercard-25" in html:
        return "25"
    return "26"   # fallback padrão


def _v(version: str, suffix: str) -> str:
    """Monta o seletor com a versão correta. Ex: _v('26','rating') → 'playercard-26-rating'"""
    return f"playercard-{version}-{suffix}"


# ════════════════════════════════════════════════════════════
# HELPERS GERAIS
# ════════════════════════════════════════════════════════════

def _txt(el) -> str | None:
    if not el:
        return None
    t = el.get_text(strip=True)
    return t if t else None


def _abs_url(src: str | None) -> str | None:
    if not src:
        return None
    src = src.strip()
    if src.startswith("http"):
        return src
    if src.startswith("//"):
        return "https:" + src
    if src.startswith("/"):
        return BASE_URL + src
    return src


def _sel(card, *selectors):
    """Tenta cada seletor em ordem e retorna o primeiro elemento encontrado."""
    for sel in selectors:
        el = card.select_one(sel)
        if el:
            return el
    return None


def _parse_css_vars(style: str) -> dict:
    """Extrai CSS custom properties de uma string de style."""
    out = {}
    if not style:
        return out
    for m in re.finditer(r"--([a-zA-Z0-9\-]+)\s*:\s*([^;]+)", style):
        out[m.group(1)] = m.group(2).strip()
    return out


# ════════════════════════════════════════════════════════════
# FACE IMAGE
# ════════════════════════════════════════════════════════════

def _pick_face(card, version: str) -> str | None:
    """Tenta special-img → base-img → qualquer playercard-img que não seja bg."""
    tag = _sel(
        card,
        f"img.{_v(version, 'special-img')}",
        f"img.{_v(version, 'base-img')}",
    )
    if tag:
        return _abs_url(tag.get("src"))
    # fallback genérico: qualquer img cujo class contenha playercard-{v}- mas não -bg
    for img in card.find_all("img"):
        cls = img.get("class", [])
        cls_str = " ".join(cls)
        if f"playercard-{version}-" in cls_str and f"playercard-{version}-bg" not in cls_str:
            return _abs_url(img.get("src"))
    return None


# ════════════════════════════════════════════════════════════
# STATS
# ════════════════════════════════════════════════════════════

def _parse_stats(card, version: str) -> dict:
    stats = {}
    container = card.select_one(f".{_v(version, 'extended-stats')}")
    if not container:
        return stats
    for item in container.select(f".{_v(version, 'stats')}"):
        key_el = item.select_one(f".{_v(version, 'stat-value')}")
        val_el = item.select_one(f".{_v(version, 'stat-number')}")
        if key_el and val_el:
            key = _txt(key_el)
            val = _txt(val_el)
            if key and val:
                stats[key.upper()] = val
    return stats


# ════════════════════════════════════════════════════════════
# INFO ROW (nação, liga, clube)
# ════════════════════════════════════════════════════════════

def _parse_info(card, version: str) -> dict:
    info = {}
    row = _sel(
        card,
        f".{_v(version, 'info-row')}",
        ".playercard-info-row",
    )
    if not row:
        return info
    for img in row.find_all("img"):
        alt   = (img.get("alt", "") or "").strip().lower()
        cls   = " ".join(img.get("class", [])).lower()
        title = (img.get("title", "") or "").strip()
        src   = _abs_url(img.get("src"))
        key   = None
        if "nation" in alt or "nation" in cls:
            key = "nation"
        elif "league" in alt or "league" in cls:
            key = "league"
        elif "club" in alt or "club" in cls:
            key = "club"
        if key:
            info[key] = {"title": title, "src": src}
    return info


# ════════════════════════════════════════════════════════════
# EXTRA INFO (pé, skill moves, weak foot, futbin rating)
# ════════════════════════════════════════════════════════════

def _parse_extra_info(card, version: str) -> dict:
    out = {
        "foot":          None,
        "skill_moves":   None,
        "weak_foot":     None,
        "futbin_rating": None,
        "style_raw":     "",
        "css_vars":      {},
        "styles":        {},
    }

    root = card.select_one(f".{_v(version, 'extra-info')}")
    if not root:
        return out

    style_raw = (root.get("style") or "").strip()
    css_vars  = _parse_css_vars(style_raw)
    out["style_raw"] = style_raw
    out["css_vars"]  = css_vars
    out["styles"]    = {
        "extra_info_bg":     css_vars.get("extra-info-bg"),
        "extra_info_border": css_vars.get("extra-info-border"),
    }

    # ── futbin rating ──────────────────────────────────────
    fr = root.select_one(f".{_v(version, 'futbin-rating')}")
    if fr:
        out["futbin_rating"] = _txt(fr)

    # ── PÉ PREFERIDO ──────────────────────────────────────
    # /25/: div dedicado
    foot_div = root.select_one(f".{_v(version, 'right-foot')}")
    if foot_div:
        t = _txt(foot_div)
        if t:
            out["foot"] = t[0].upper()

    # ── SKILL MOVES ───────────────────────────────────────
    skills_div = root.select_one(f".{_v(version, 'right-skills')}")
    if skills_div:
        t = _txt(skills_div)
        if t:
            m = re.search(r"\d+", t)
            if m:
                out["skill_moves"] = int(m.group())

    # ── WEAK FOOT ─────────────────────────────────────────
    # /25/: div dedicado
    wf_div = root.select_one(f".{_v(version, 'right-wf')}")
    if wf_div:
        span = wf_div.select_one("span")
        if span:
            t = _txt(span)
            if t and t.isdigit():
                out["weak_foot"] = int(t)
        if out["weak_foot"] is None:
            t = _txt(wf_div)
            if t:
                m = re.search(r"\d+", t)
                if m:
                    out["weak_foot"] = int(m.group())

    # ── FALLBACKS via basic-items (/26/ usa essa estrutura) ──
    basic_items = root.select(f".{_v(version, 'extra-info-basic-item')}")
    basic_texts = [_txt(b) or "" for b in basic_items]

    if out["foot"] is None and basic_texts:
        t0 = basic_texts[0].strip().upper()
        if t0 in ("R", "L"):
            out["foot"] = t0

    if out["skill_moves"] is None:
        for bt in basic_texts:
            m = re.search(r"\d+", bt)
            if m:
                n = int(m.group())
                if 1 <= n <= 5:
                    out["skill_moves"] = n
                    break

    # Weak foot via foot-svg → parent basic-item
    if out["weak_foot"] is None:
        foot_svg = root.select_one(f"svg.{_v(version, 'foot-svg')}")
        if not foot_svg:
            # /25/ usa right-foot-svg ou left-foot-svg
            foot_svg = root.select_one(
                f"svg.{_v(version, 'right-foot-svg')}, "
                f"svg.{_v(version, 'left-foot-svg')}"
            )
        if foot_svg:
            parent = foot_svg.find_parent(class_=re.compile(r"extra-info-basic-item"))
            if parent:
                t = _txt(parent)
                if t:
                    m = re.search(r"\d+", t)
                    if m:
                        out["weak_foot"] = int(m.group())

    return out


# ════════════════════════════════════════════════════════════
# ALT SIDEBAR — posições alternativas
# ════════════════════════════════════════════════════════════

def _parse_alt_sidebar_right(card, version: str) -> dict:
    right = card.select_one(f".{_v(version, 'alt-sidebar')}.right")
    if not right:
        all_sidebars = card.select(f".{_v(version, 'alt-sidebar')}")
        if len(all_sidebars) == 1:
            right = all_sidebars[0]
    if not right:
        return {"positions": [], "styles": {}, "css_vars": {}, "style_raw": ""}

    alt_pos = right.select_one(f".{_v(version, 'alt-pos')}")
    style_raw = (alt_pos.get("style") or "").strip() if alt_pos else ""
    css_vars  = _parse_css_vars(style_raw)

    positions = []
    for sub in right.select(f".{_v(version, 'alt-pos-sub')}"):
        label = None
        for node in sub.children:
            if hasattr(node, "string") and node.string:
                t = node.string.strip()
                if t:
                    label = t
                    break
            elif isinstance(node, str):
                t = node.strip()
                if t:
                    label = t
                    break
        if not label:
            label = _txt(sub)
        pp = bool(sub.select_one(f".{_v(version, 'alt-pos-sub-plus-plus')}"))
        if label:
            positions.append({"pos": label, "plus_plus": pp})

    return {
        "positions": positions,
        "style_raw": style_raw,
        "css_vars":  css_vars,
        "styles": {
            "alt_pos_background": css_vars.get("alt-pos-background"),
            "alt_pos_border":     css_vars.get("alt-pos-border"),
        },
    }


# ════════════════════════════════════════════════════════════
# PLAYSTYLES
# ════════════════════════════════════════════════════════════

def _parse_playstyles(card, version: str) -> dict:
    out = {"count": 0, "classes": [], "items": []}

    left = card.select_one(f".{_v(version, 'alt-sidebar')}.left")
    wrap = None
    if left:
        wrap = left.select_one(f".{_v(version, 'playstyles-wrapper')}")
    if not wrap:
        wrap = card.select_one(f".{_v(version, 'playstyles-wrapper')}")
    if not wrap:
        return out

    for svg in wrap.find_all("svg"):
        cls       = " ".join(svg.get("class", [])).strip()
        style_raw = (svg.get("style") or "").strip()
        css_vars  = _parse_css_vars(style_raw)
        out["classes"].append(cls)
        out["items"].append({
            "class":     cls,
            "style_raw": style_raw,
            "css_vars":  css_vars,
            "viewBox":   svg.get("viewBox") or svg.get("viewbox"),
            "width":     svg.get("width"),
            "height":    svg.get("height"),
            "svg_raw":   str(svg),   # markup completo do SVG para renderização
        })

    out["count"] = len(out["items"])
    return out


# ════════════════════════════════════════════════════════════
# PREÇOS (só usado se fetch_prices=True)
# ════════════════════════════════════════════════════════════

def _parse_prices(soup) -> dict:
    def norm(txt):
        if not txt:
            return None
        t = txt.strip()
        digits = re.sub(r"\D", "", t)
        if not digits or int(digits) == 0:
            return None
        return t

    def last_valid(els):
        last = None
        for el in els:
            v = norm(el.get_text(strip=True))
            if v:
                last = v
        return last

    ps_box = soup.select_one(".price-box.platform-ps-only, .price-box.platform-ps")
    pc_box = soup.select_one(".price-box.platform-pc-only, .price-box.platform-pc")

    console   = None
    pc        = None
    estimated = last_valid(soup.select(".estimated-price"))

    if ps_box:
        console = last_valid(ps_box.select(".lowest-price")) or last_valid(ps_box.select(".estimated-price"))
    if pc_box:
        pc = last_valid(pc_box.select(".lowest-price")) or last_valid(pc_box.select(".estimated-price"))

    if not console:
        console = last_valid(soup.select(".lowest-price"))
    if not pc and estimated:
        pc = estimated

    return {"console": console, "pc": pc, "estimated": estimated}


# ════════════════════════════════════════════════════════════
# PLAYER URL — descobre o link do detalhe do jogador
# ════════════════════════════════════════════════════════════

def _find_player_url(card, version: str) -> str | None:
    # 1ª tentativa: link direto com padrão /{version}/player/{id}/
    for a in card.find_all("a", href=True):
        href = a["href"]
        if re.search(r"/\d+/player/\d+/", href):
            return href if href.startswith("http") else BASE_URL + href

    # 2ª tentativa: <a> pai — quando o card é filho direto do link (listing page)
    parent = card.parent
    if parent and parent.name == "a":
        href = parent.get("href", "")
        if re.search(r"/\d+/player/\d+/", href):
            return href if href.startswith("http") else BASE_URL + href

    # 3ª tentativa: data-player-hover-location
    hover_el = card.select_one("[data-player-hover-location]")
    if hover_el:
        hover_path = (hover_el.get("data-player-hover-location") or "").strip()
        if hover_path:
            face_img = hover_el.select_one(
                f"img.{_v(version, 'special-img')}, img[src*='/players/p']"
            )
            if face_img:
                m = re.search(r"/players/p(\d+)\.", face_img.get("src", ""))
                if m:
                    ext_id = m.group(1)
                    card_el = hover_el.select_one(f".playercard-{version}")
                    raw_name = ""
                    if card_el:
                        name_el = card_el.select_one(f".{_v(version, 'name')}")
                        raw_name = _txt(name_el) or card_el.get("title", "")
                    slug = re.sub(r"[^a-z0-9]+", "-", raw_name.lower()).strip("-") or "player"
                    year_m = re.match(r"^/(\d+)/", hover_path)
                    year   = year_m.group(1) if year_m else version
                    return f"{BASE_URL}/{year}/player/{ext_id}/{slug}"
            # fallback: usa o hover path diretamente
            return hover_path if hover_path.startswith("http") else BASE_URL + hover_path

    return None


# ════════════════════════════════════════════════════════════
# PARSE DE CARD COMPLETO (usado na página do jogador)
# ════════════════════════════════════════════════════════════

def parse_full_player(html: str, player_url: str = None, fetch_prices: bool = False) -> dict:
    """
    Recebe o HTML da página individual do jogador.
    Retorna o bloco completo compatível com o contrato WordPress.
    """
    soup    = BeautifulSoup(html, "lxml")
    version = detect_version(html)

    card = soup.select_one(f".playercard-{version}")
    if not card:
        log.warning("Card não encontrado em: %s", player_url)
        return {"player_url": player_url}

    style_raw = (card.get("style") or "").strip()
    css_vars  = _parse_css_vars(style_raw)

    rating   = _txt(card.select_one(f".{_v(version, 'rating')}"))
    position = _txt(card.select_one(f".{_v(version, 'position')}"))
    role_plus= _txt(card.select_one(f".{_v(version, 'role-plus')}"))
    name     = _txt(card.select_one(f".{_v(version, 'name')}"))

    bg_tag   = card.select_one(f"img.{_v(version, 'bg')}")
    bg_src   = _abs_url(bg_tag.get("src")) if bg_tag else None
    face_src = _pick_face(card, version)

    stats       = _parse_stats(card, version)
    info        = _parse_info(card, version)
    extra_info  = _parse_extra_info(card, version)
    alt_sidebar = _parse_alt_sidebar_right(card, version)
    playstyles  = _parse_playstyles(card, version)
    prices      = _parse_prices(soup) if fetch_prices else {"console": None, "pc": None, "estimated": None}

    log.info(
        "  Player: %s | %s %s | Console=%s PC=%s",
        name, rating, position,
        prices["console"] or "N/A",
        prices["pc"] or "N/A",
    )

    return {
        "player_url":     player_url,
        "name":           name,
        "rating":         rating,
        "position":       position,
        "role_plus":      role_plus,
        "images":         {"bg": bg_src, "face": face_src},
        "stats":          stats,
        "info":           info,
        "prices":         prices,
        "card_style_raw": style_raw,
        "card_css_vars":  css_vars,
        "alt_sidebar":    alt_sidebar,
        "playstyles":     playstyles,
        "extra_info":     extra_info,
    }


# ════════════════════════════════════════════════════════════
# EXTRAÇÃO DE CARDS DA LISTAGEM
# ════════════════════════════════════════════════════════════

def extract_cards(html: str) -> list[dict]:
    """
    Recebe o HTML da página de listagem (TOTW, etc.).
    Retorna lista de dicts com dados básicos + player_url de cada jogador.
    """
    soup    = BeautifulSoup(html, "lxml")
    version = detect_version(html)
    cards   = soup.select(f".playercard-{version}")

    result = []
    for i, card in enumerate(cards):
        name     = _txt(card.select_one(f".{_v(version, 'name')}"))
        rating   = _txt(card.select_one(f".{_v(version, 'rating')}"))
        position = _txt(card.select_one(f".{_v(version, 'position')}"))

        bg_tag   = card.select_one(f"img.{_v(version, 'bg')}")
        bg_src   = _abs_url(bg_tag.get("src")) if bg_tag else None
        face_src = _pick_face(card, version)

        player_url = _find_player_url(card, version)

        log.debug("[%d] %s %s %s → %s", i, rating, position, name, player_url)

        result.append({
            "sort_index":   i,
            "name":         name,
            "rating":       rating,
            "position":     position,
            "images":       {"bg": bg_src, "face": face_src},
            "player_url":   player_url,
            "version":      version,
        })

    log.info("extract_cards: %d cards encontrados (versão /25/ → /26/: %s)", len(result), version)
    return result
