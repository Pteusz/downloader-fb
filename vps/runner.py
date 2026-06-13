"""
runner.py
─────────
Entry point do futbin-scraper.

Uso:
    python runner.py \\
        --module totw \\
        --url    "https://www.futbin.com/26/totw/BundesligaTOTS" \\
        --ajax   "https://seusite.com/wp-admin/admin-ajax.php" \\
        --nonce  "SEU_WP_NONCE"

Variáveis de ambiente (alternativa a flags CLI):
    FUTBIN_AJAX_URL   → URL do admin-ajax.php
    FUTBIN_WP_NONCE   → nonce do WordPress

Módulos disponíveis: totw
"""

import argparse
import logging
import os
import sys
import time

# ── Logging ──────────────────────────────────────────────────
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(levelname)-8s %(message)s",
    datefmt="%H:%M:%S",
)
log = logging.getLogger(__name__)

# ── Mapa de módulos disponíveis ──────────────────────────────
MODULES = {
    "totw":        "modules.totw",
    "squads_list": "modules.squads_list",
}


def _import_module(name: str):
    import importlib
    return importlib.import_module(MODULES[name])


def main():
    parser = argparse.ArgumentParser(description="Futbin Scraper — VPS")
    parser.add_argument("--module", required=True, choices=list(MODULES.keys()),
                        help="Módulo a executar (ex: totw)")
    parser.add_argument("--url", required=True,
                        help="URL da página a ser scrapeada")
    parser.add_argument("--ajax", default=None,
                        help="URL do wp-admin/admin-ajax.php")
    parser.add_argument("--nonce", default=None,
                        help="WordPress nonce (action: fhub_nonce)")
    parser.add_argument("--token", default=None,
                        help="Scrape token personalizado (default: gerado automaticamente)")
    args = parser.parse_args()

    # ── Resolve configurações (CLI > env > erro) ─────────────
    ajax_url = args.ajax or os.environ.get("FUTBIN_AJAX_URL")
    nonce    = args.nonce or os.environ.get("FUTBIN_WP_NONCE")

    if not ajax_url:
        log.error("--ajax ou FUTBIN_AJAX_URL é obrigatório.")
        sys.exit(1)
    if not nonce:
        log.error("--nonce ou FUTBIN_WP_NONCE é obrigatório.")
        sys.exit(1)

    # ── Token de execução ────────────────────────────────────
    if args.token:
        scrape_token = args.token
    else:
        import hashlib
        scrape_token = hashlib.md5(
            f"{args.url}{time.time()}".encode()
        ).hexdigest()[:16]

    log.info("Módulo  : %s", args.module)
    log.info("URL     : %s", args.url)
    log.info("Ajax    : %s", ajax_url)
    log.info("Token   : %s", scrape_token)

    # ── Executa o módulo ─────────────────────────────────────
    mod    = _import_module(args.module)
    result = mod.run(
        listing_url=args.url,
        scrape_token=scrape_token,
        ajax_url=ajax_url,
        nonce=nonce,
    )

    # ── Resultado final ──────────────────────────────────────
    log.info("─" * 50)
    for k, v in result.items():
        log.info("  %-12s: %s", k, v)
    log.info("─" * 50)

    if result.get("error"):
        sys.exit(1)


if __name__ == "__main__":
    main()
