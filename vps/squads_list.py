"""
modules/squads_list.py
──────────────────────
Descobre squads disponíveis no Futbin e atualiza squads_index.json.

Chamado via runner.py:
    python runner.py --module squads_list --url "https://www.futbin.com/26/squads"

O content.js já trata /squads/ como isSquadsListing e extrai a.squad-box.
Este módulo parseia o HTML retornado e faz merge com squads_index.json.
"""

import json
import logging
import os
import re
from datetime import datetime

from bs4 import BeautifulSoup

from core import job_queue

log = logging.getLogger(__name__)

DATA_DIR          = "/data"
SQUADS_INDEX_PATH = os.path.join(DATA_DIR, "squads_index.json")
BASE_URL          = "https://www.futbin.com"


# ════════════════════════════════════════════════════════════
# ENTRY POINT
# ════════════════════════════════════════════════════════════

def run(listing_url: str, scrape_token: str, ajax_url: str, nonce: str) -> dict:
    log.info("═══ Squads Discovery | %s ═══", listing_url)

    html = job_queue.fetch(listing_url)
    if not html:
        log.error("Não foi possível obter HTML da listagem de squads.")
        return {"error": "fetch_failed", "squads": 0, "new": 0}

    discovered = _parse_squads(html)
    log.info("[Parse] %d squads encontrados na página", len(discovered))

    if not discovered:
        log.warning("Nenhum a.squad-box encontrado — estrutura da página pode ter mudado.")
        return {"error": "no_squads_found", "squads": 0, "new": 0}

    total, added = _merge(discovered)
    log.info("═══ Concluído | total=%d novos=%d ═══", total, added)
    return {"squads": total, "new": added}


# ════════════════════════════════════════════════════════════
# PARSER
# ════════════════════════════════════════════════════════════

def _parse_squads(html: str) -> list[dict]:
    """
    Parseia o HTML de a.squad-box enviado pelo content.js.
    Cada elemento é o outerHTML de um <a class="squad-box"> do Futbin.
    """
    soup   = BeautifulSoup(html, "lxml")
    result = []

    for a in soup.select("a.squad-box"):
        href = (a.get("href") or "").strip()
        if not href:
            continue

        url = href if href.startswith("http") else BASE_URL + href

        # ── Nome ────────────────────────────────────────────
        name = None
        for sel in [".squad-box-name", ".squad-name", ".name", "h3", "h4", "strong", "b"]:
            el = a.select_one(sel)
            if el:
                t = el.get_text(strip=True)
                if t:
                    name = t
                    break
        if not name:
            # fallback: último segmento da URL humanizado
            slug = url.rstrip("/").split("/")[-1]
            name = re.sub(r"([A-Z])", r" \1", slug).strip()

        # ── Data ─────────────────────────────────────────────
        created = None
        for sel in [".squad-box-date", ".squad-date", ".date", "time", "small"]:
            el = a.select_one(sel)
            if el:
                t = (el.get("datetime") or el.get_text(strip=True) or "").strip()
                if t:
                    created = t
                    break
        if not created:
            created = datetime.utcnow().strftime("%d.%m.%Y")

        # ── Imagem de fundo ──────────────────────────────────
        bg_image = None
        for el in a.find_all(True):
            style = el.get("style", "")
            m = re.search(
                r"background(?:-image)?\s*:\s*url\(['\"]?([^'\")\s]+)['\"]?\)", style
            )
            if m:
                src = m.group(1)
                bg_image = ("https:" + src) if src.startswith("//") else src
                break
        if not bg_image:
            img = a.select_one("img[src], img[data-src]")
            if img:
                src = (img.get("src") or img.get("data-src") or "").strip()
                if src:
                    bg_image = ("https:" + src) if src.startswith("//") else src

        result.append({
            "name":     name,
            "url":      url,
            "created":  created,
            "bg_image": bg_image or "",
        })
        log.debug("  squad: %s → %s", name, url)

    return result


# ════════════════════════════════════════════════════════════
# MERGE com squads_index.json
# ════════════════════════════════════════════════════════════

def _merge(new_squads: list[dict]) -> tuple[int, int]:
    """
    Faz merge dos squads descobertos com o índice existente.
    Nunca remove entradas existentes — só adiciona novas.
    Retorna (total_após_merge, qtd_adicionados).
    """
    existing: list[dict] = []
    if os.path.exists(SQUADS_INDEX_PATH):
        try:
            with open(SQUADS_INDEX_PATH, encoding="utf-8") as f:
                existing = json.load(f)
        except Exception as e:
            log.warning("Erro ao ler squads_index.json: %s — recriando.", e)
            existing = []

    known = {s["url"].rstrip("/").split("/")[-1] for s in existing}

    added = 0
    for squad in new_squads:
        label = squad["url"].rstrip("/").split("/")[-1]
        if label not in known:
            existing.append(squad)
            known.add(label)
            added += 1
            log.info("  + Novo squad adicionado: %s", squad["name"])
        else:
            log.debug("  = Já existe: %s", squad["name"])

    with open(SQUADS_INDEX_PATH, "w", encoding="utf-8") as f:
        json.dump(existing, f, ensure_ascii=False, indent=2)

    return len(existing), added
