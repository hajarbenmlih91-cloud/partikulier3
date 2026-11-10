#!/usr/bin/env python3
"""Extrait du workflow d'audit les trois blocs bash modifiés, pour les tester isolément.

Usage : gen.py <chemin/.github/workflows/audit-uat.yml> <répertoire de sortie>

Produit guard.sh, lighthouse_fn.sh, cache_probe.sh — copies exactes des blocs `run:`
correspondants, à l'exception de la constante APP_BASE_COMMIT, remplacée par ${BASE}
pour pouvoir rejouer la garde sur un dépôt de test.
"""
import pathlib
import sys

y = pathlib.Path(sys.argv[1]).read_text()
out = pathlib.Path(sys.argv[2])
out.mkdir(parents=True, exist_ok=True)


def block(name_start):
    i = y.index("      - name: " + name_start)
    j = y.index("\n      - name: ", i + 10)
    return y[i:j]


# 1. garde de traçabilité
g = block("Traçabilité")
run = g.split("        run: |\n", 1)[1]
run = run.replace(
    "APP_BASE_COMMIT='c9e141376179f1a47e567361c28e747b6e891cd0'",
    'APP_BASE_COMMIT="${BASE:?}"',
)
(out / "guard.sh").write_text(run)

# 2. fonction run_lh (Lighthouse)
lh = block("Lighthouse mobile et desktop")
lr = lh.split("        run: |\n", 1)[1]
i = lr.index("          lh_failed=0")
j = lr.index("          run_lh fr-home-mobile")
(out / "lighthouse_fn.sh").write_text(lr[i:j])

# 3. sonde serveur du cache du thème, jusqu'au contrôle d'acceptation inclus
i = y.index("          {\n            echo ''\n            echo '== CACHE DU THÈME")
k = y.index('          exit "$php_rc"', i)
(out / "cache_probe.sh").write_text(y[i:k])

print("blocs extraits dans", out)
